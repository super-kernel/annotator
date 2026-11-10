<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Cache\AnnotationPublisher;
use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Tests\Fixture\CollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\FixtureMode;
use SuperKernel\Annotator\Tests\Support\TemporaryVendorDirectory;
use SuperKernel\Annotator\Tests\Support\TestCase;
use Throwable;

use function array_keys;
use function fileperms;
use function umask;

final class AnnotationPublisherTest extends TestCase
{
    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testUnsortedRecordsInAnyOrderPublishTheSameFilesAndRestore(): void
    {
        $manager = $this->packageManager([
            $this->package('acme/app', null),
            $this->package('acme/library', 'reference-a'),
        ]);
        $attribute = new AttributeMetadata(new CollectedAttribute('value', FixtureMode::Ready));
        $unsorted = [
            new PackageAnnotations('acme/library', 'reference-a', [
                'Acme\\Zeta' => [
                    $attribute,
                ],
                'Acme\\Alpha' => [
                    $attribute,
                ],
            ], [
                'Acme\\Zeta' => [
                    'second' => [
                        $attribute,
                    ],
                    'first' => [
                        $attribute,
                    ],
                ],
            ], [], [
                [
                    'sequence'       => 1,
                    'package'        => 'acme/library',
                    'class'          => 'Acme\\Broken',
                    'exception_type' => 'Error',
                    'message'        => 'Unavailable parent',
                ],
            ]),
            new PackageAnnotations('acme/app', null, [], [], []),
        ];
        $sorted = [
            new PackageAnnotations('acme/app', null, [], [], []),
            new PackageAnnotations('acme/library', 'reference-a', [
                'Acme\\Alpha' => [
                    $attribute,
                ],
                'Acme\\Zeta' => [
                    $attribute,
                ],
            ], [
                'Acme\\Zeta' => [
                    'first' => [
                        $attribute,
                    ],
                    'second' => [
                        $attribute,
                    ],
                ],
            ], [], $unsorted[0]->getDiagnostics()),
        ];
        $unsortedVendor = new TemporaryVendorDirectory();
        $sortedVendor = new TemporaryVendorDirectory();

        new AnnotationPublisher($unsortedVendor)->publish($manager, $unsorted);
        new AnnotationPublisher($sortedVendor)->publish($manager, $sorted);

        self::assertSame($sortedVendor->files(), $unsortedVendor->files());
        self::assertSame(0o666 & ~umask(), fileperms($unsortedVendor . '/.cache/packages.ser') & 0o777);
        self::assertSame(
            0o666 & ~umask(),
            fileperms($unsortedVendor . '/.cache/annotations/acme/library.ser') & 0o777,
        );

        $collector = new AnnotationCache($unsortedVendor)->read($manager);

        self::assertSame([
            'Acme\\Alpha',
            'Acme\\Zeta',
        ], array_keys($collector->getClassesWithAttribute(CollectedAttribute::class)));
        self::assertSame([
            'first',
            'second',
        ], array_keys($collector->getMethodsWithAttribute(CollectedAttribute::class)['Acme\\Zeta']));
        self::assertCount(1, $collector->getDiagnostics());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testFailedPublicationRemovesEveryRecord(): void
    {
        $manager = $this->packageManager([
            $this->package('acme/app', null),
        ]);
        $vendorDir = new TemporaryVendorDirectory();
        $publisher = new AnnotationPublisher($vendorDir);
        $publisher->publish($manager, [
            new PackageAnnotations('acme/app', null, [], [], []),
        ]);
        self::assertNotSame([], $vendorDir->files());

        $this->assertCacheFailure(
            static fn() => $publisher->publish($manager, [
                'acme/app',
            ]),
            'must be PackageAnnotations',
        );
        self::assertSame([], $vendorDir->files());

        $publisher->publish($manager, [
            new PackageAnnotations('acme/app', null, [], [], []),
        ]);
        $this->assertCacheFailure(
            static fn() => $publisher->publish($manager, [
                new PackageAnnotations('acme/app', 'unexpected', [], [], []),
            ]),
            'reference mismatch',
        );
        self::assertSame([], $vendorDir->files());
        $this->assertCacheFailure(
            static fn() => new AnnotationCache($vendorDir)->read($manager),
            'stub is unavailable',
        );
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRepeatedPackageRecordIsRejectedAndRemovesEveryRecord(): void
    {
        $manager = $this->packageManager([
            $this->package('acme/app', null),
            $this->package('acme/library', 'reference-a'),
        ]);
        $vendorDir = new TemporaryVendorDirectory();
        $publisher = new AnnotationPublisher($vendorDir);
        $publisher->publish($manager, [
            new PackageAnnotations('acme/app', null, [], [], []),
            new PackageAnnotations('acme/library', 'reference-a', [], [], []),
        ]);
        self::assertNotSame([], $vendorDir->files());

        $this->assertCacheFailure(
            static fn() => $publisher->publish($manager, [
                new PackageAnnotations('acme/library', 'reference-a', [
                    'Acme\\Alpha' => [
                        new AttributeMetadata(new CollectedAttribute('value', FixtureMode::Ready)),
                    ],
                ], [], []),
                new PackageAnnotations('acme/library', 'reference-a', [], [], []),
                new PackageAnnotations('acme/app', null, [], [], []),
            ]),
            'Package acme/library has more than one published annotation record.',
        );
        self::assertSame([], $vendorDir->files());
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertCacheFailure(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (AnnotationCacheException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());

            return;
        }

        self::fail('Expected an AnnotationCacheException.');
    }
}
