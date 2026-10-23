<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use RuntimeException;
use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Scan\AnnotationScanner;
use SuperKernel\Annotator\Scan\AnnotationScanSession;
use SuperKernel\Annotator\Scan\AnnotationScanWorker;
use SuperKernel\Annotator\Tests\Fixture\InvalidAnnotatedFixture;
use SuperKernel\Annotator\Tests\Support\TemporaryVendorDirectory;
use SuperKernel\Annotator\Tests\Support\TestCase;
use SuperKernel\Contract\PackageManagerInterface;
use Throwable;

use function mkdir;
use function symlink;

final class AnnotationScanWorkerTest extends TestCase
{
    /** @throws Throwable When fixture preparation fails */
    public function testStructuralFailureFlushesCollectedDiagnosticsAndPreservesTheOriginalFailure(): void
    {
        $failure = new RuntimeException('structural scan failure');
        $manager = $this->createStub(PackageManagerInterface::class);
        $manager->method('all')->willReturn([
            $this->package('acme/first', null),
            $this->package('acme/second', null),
        ]);
        $manager->method('classes')->willReturnCallback(static fn(string $name): array => 'acme/first' === $name
            ? [
                InvalidAnnotatedFixture::class => __DIR__ . '/../Fixture/InvalidAnnotatedFixture.php',
            ] : throw $failure);
        $vendor = new TemporaryVendorDirectory();
        try {
            AnnotationScanWorker::run($manager, $vendor, $this->reflector());
            self::fail('The structural failure must escape the worker.');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame([], $vendor->files());
    }

    /** @throws Throwable When fixture preparation fails */
    public function testPublicationFailureFlushesDiagnosticsAndInvalidatesIncompleteResults(): void
    {
        $manager = $this->packageManager([
            $this->package('acme/first', null),
        ], [
            InvalidAnnotatedFixture::class => __DIR__ . '/../Fixture/InvalidAnnotatedFixture.php',
        ]);
        $vendor = new TemporaryVendorDirectory();
        mkdir($vendor . '/.cache/annotations/acme/first.ser', recursive: true);
        try {
            AnnotationScanWorker::run($manager, $vendor, $this->reflector());
            self::fail('The publication failure must escape the worker.');
        } catch (AnnotationCacheException $failure) {
            self::assertStringContainsString('Unable to publish', $failure->getMessage());
        }
        self::assertSame([], $vendor->files());
    }

    /** @throws Throwable When fixture preparation fails */
    public function testCleanupFailureDoesNotMaskTheScanFailureOrRepublishPendingData(): void
    {
        $manager = $this->createStub(PackageManagerInterface::class);
        $failure = new RuntimeException('original scan failure');
        $manager->method('all')->willThrowException($failure);
        $vendor = new TemporaryVendorDirectory();
        $target = new TemporaryVendorDirectory();
        symlink((string) $target, $vendor . '/.cache');
        $session = new AnnotationScanSession(
            $manager,
            new AnnotationScanner($manager, $this->reflector()),
            new AnnotationCache($vendor),
        );
        try {
            $session->scan();
            self::fail('The original failure must escape even when invalidation fails.');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        unset($session);
        self::assertSame([], $target->files());
    }
}
