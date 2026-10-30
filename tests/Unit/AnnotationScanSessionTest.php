<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Constants\CacheLayout;
use SuperKernel\Annotator\Exception\AnnotationScanException;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Scan\AnnotationScanner;
use SuperKernel\Annotator\Scan\AnnotationScanSession;
use SuperKernel\Annotator\Tests\Fixture\AnnotatedFixture;
use SuperKernel\Annotator\Tests\Support\TemporaryVendorDirectory;
use SuperKernel\Annotator\Tests\Support\TestCase;
use SuperKernel\Contract\PackageManagerInterface;
use Throwable;

use function dirname;

final class AnnotationScanSessionTest extends TestCase
{
    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPublishesCompletedScanDataFromItsDestructor(): void
    {
        $package = $this->package('acme/fixture', 'fixture-reference');
        $class = AnnotatedFixture::class;
        $manager = $this->packageManager(
            [
                $package,
            ],
            [
                $class => dirname(__DIR__) . '/Fixture/AnnotatedFixture.php',
            ],
            [
                $class => $package,
            ],
        );
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $session = new AnnotationScanSession(
            $manager,
            new AnnotationScanner($manager, $this->reflector()),
            $cache,
        );

        $session->scan();
        self::assertFalse($vendorDir->exists(CacheLayout::IndexPath->value));

        unset($session);

        self::assertTrue($vendorDir->exists(CacheLayout::IndexPath->value));
        self::assertNotEmpty($cache->read($manager)->getClassAttributes($class));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testInvalidatesExistingSerializedDataWhenScanningFails(): void
    {
        $package = $this->package('acme/fixture', 'fixture-reference');
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $cache->write($this->packageManager([
            $package,
        ]), [
            new PackageAnnotations('acme/fixture', 'fixture-reference', [], [], []),
        ]);
        $manager = $this->createStub(PackageManagerInterface::class);
        $manager->method('all')->willReturn([
            $package,
        ]);
        $manager->method('classes')->willReturn(null);
        $session = new AnnotationScanSession(
            $manager,
            new AnnotationScanner($manager, $this->reflector()),
            $cache,
        );

        $this->expectException(AnnotationScanException::class);

        try {
            $session->scan();
        } finally {
            unset($session);

            self::assertFalse($vendorDir->exists(CacheLayout::IndexPath->value));
            self::assertFalse($vendorDir->exists(CacheLayout::PackageDirectory->value));
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testDoesNotPublishAnEarlierResultAfterALaterScanFails(): void
    {
        $manager = $this->createStub(PackageManagerInterface::class);
        $manager->method('all')->willReturn([
            $this->package('acme/fixture', null),
        ]);
        $manager->method('classes')->willReturn([], null);
        $vendorDir = new TemporaryVendorDirectory();
        $session = new AnnotationScanSession(
            $manager,
            new AnnotationScanner($manager, $this->reflector()),
            new AnnotationCache($vendorDir),
        );
        $session->scan();

        $this->expectException(AnnotationScanException::class);

        try {
            $session->scan();
        } finally {
            unset($session);

            self::assertSame([], $vendorDir->files());
        }
    }
}
