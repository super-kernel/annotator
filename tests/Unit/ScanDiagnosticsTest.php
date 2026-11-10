<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Cache\AnnotationPublisher;
use SuperKernel\Annotator\Cache\SerializedPackageAnnotations;
use SuperKernel\Annotator\Cache\SerializedPackageReferences;
use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Tests\Support\TemporaryVendorDirectory;
use SuperKernel\Annotator\Tests\Support\TestCase;
use SuperKernel\Contract\PackageAnnotationsInterface;
use SuperKernel\Contract\ScanDiagnosticsInterface;
use Throwable;

use function array_keys;
use function class_exists;
use function serialize;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_repeat;

final class ScanDiagnosticsTest extends TestCase
{
    /** @throws Throwable When fixture setup, publication, or restoration fails */
    public function testPublishedDiagnosticsKeepOriginalBytesAndBuildOrderAcrossSortedPackages(): void
    {
        $manager = $this->packageManager([
            $this->package('z/root', null),
            $this->package('a/dependency', null),
        ]);
        $failure = new class('') extends RuntimeException {};
        $root = [
            'sequence'       => 1,
            'package'        => 'z/root',
            'class'          => 'Unloaded\\Root',
            'exception_type' => $failure::class,
            'message'        => "\x00\n\r\t\033\xff" . str_repeat('x', 8192),
        ];
        $dependency = [
            'sequence'       => 2,
            'package'        => 'a/dependency',
            'class'          => 'Unloaded\\Dependency',
            'exception_type' => 'Error',
            'message'        => '',
        ];
        $vendor = new TemporaryVendorDirectory();
        new AnnotationPublisher($vendor)->publish($manager, [
            new PackageAnnotations('z/root', null, [], [], [], [
                $root,
            ]),
            new PackageAnnotations('a/dependency', null, [], [], [], [
                $dependency,
            ]),
        ]);

        $restored = new AnnotationCache($vendor)->read($manager);

        self::assertSame([
            'a/dependency',
            'z/root',
        ], array_keys($restored->getPackages()));
        self::assertInstanceOf(ScanDiagnosticsInterface::class, $restored);
        self::assertSame([
            $root,
            $dependency,
        ], $restored->getDiagnostics());
        self::assertSame([], $restored->getClassAttributes('Unloaded\\Root'));
        self::assertFalse(class_exists('Unloaded\\Root', false));
        self::assertFalse(class_exists('Unloaded\\Dependency', false));
    }

    /** @throws Throwable When a legacy package cannot be stubbed */
    public function testIndependentLegacyPackageStillWorksWithoutDiagnostics(): void
    {
        $package = $this->createStub(PackageAnnotationsInterface::class);
        $package->method('packageName')->willReturn('legacy/package');
        $collector = new AnnotationCollector([
            $package,
        ]);
        self::assertSame($package, $collector->getPackage('legacy/package'));
        self::assertSame([], $collector->getDiagnostics());
    }

    /**
     * @param array<array-key, mixed> $diagnostics
     * @param array<string, array{}>  $classes
     *
     * @throws Throwable When cache preparation or restoration fails
     */
    #[DataProvider('invalidDiagnostics')]
    public function testRejectsMalformedDiagnosticsBeforeWritingAndOnRestoration(
        array $diagnostics,
        array $classes = [],
    ): void
    {
        $manager = $this->packageManager([
            $this->package('acme/fixture', null),
        ]);
        $vendor = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendor);
        $record = new PackageAnnotations('acme/fixture', null, $classes, [], [], $diagnostics);
        try {
            $cache->write($manager, [
                $record,
            ]);
            self::fail('Invalid diagnostics must never be published.');
        } catch (AnnotationCacheException) {
            self::assertSame([], $vendor->files());
        }
        $vendor->write('packages.ser', serialize(new SerializedPackageReferences([
            'acme/fixture' => null,
        ])));
        $vendor->write('annotations/acme/fixture.ser', serialize(new SerializedPackageAnnotations($record)));
        $this->expectException(AnnotationCacheException::class);
        $cache->read($manager);
    }

    /** @return iterable<string, array{array<array-key, mixed>, array<string, array{}>}> */
    public static function invalidDiagnostics(): iterable
    {
        $valid = self::diagnostic();
        foreach ([
            'sequence' => [
                0,
                -1,
                '1',
                1.5,
                null,
            ],
            'package' => [
                'other/package',
                "acme/fixture\x00",
                null,
            ],
            'class' => [
                '',
                null,
                [],
            ],
            'exception_type' => [
                '',
                new RuntimeException('not scalar'),
            ],
            'message' => [
                null,
                [],
                new RuntimeException('not scalar'),
            ],
        ] as $field => $values) {
            foreach ($values as $index => $value) {
                $row = $valid;
                $row[$field] = $value;
                yield $field . $index => [
                    [
                        $row,
                    ],
                    [],
                ];
            }
        }
        $extra = $valid;
        $extra['unknown'] = true;
        yield 'unknown field' => [
            [
                $extra,
            ],
            [],
        ];
        unset($extra['unknown'], $extra['message']);
        yield 'missing field' => [
            [
                $extra,
            ],
            [],
        ];
        yield 'not a list' => [
            [
                'row' => $valid,
            ],
            [],
        ];
        yield 'object row' => [
            [
                (object) $valid,
            ],
            [],
        ];
        yield 'duplicate sequence' => [
            [
                $valid,
                $valid,
            ],
            [],
        ];
        $second = $valid;
        $second['sequence'] = 2;
        yield 'duplicate class' => [
            [
                $valid,
                $second,
            ],
            [],
        ];
        yield 'partial metadata' => [
            [
                $valid,
            ],
            [
                $valid['class'] => [],
            ],
        ];
        $second['class'] = 'Missing\\Another';
        $second['sequence'] = 3;
        yield 'sequence gap' => [
            [
                $valid,
                $second,
            ],
            [],
        ];
    }

    /** @throws Throwable When cache setup fails */
    public function testRejectsDuplicateGlobalSequenceAcrossOtherwiseValidPackages(): void
    {
        $manager = $this->packageManager([
            $this->package('acme/fixture', null),
            $this->package('other/package', null),
        ]);
        $second = self::diagnostic();
        $second['package'] = 'other/package';
        $this->expectException(AnnotationCacheException::class);
        new AnnotationCache(new TemporaryVendorDirectory())->write($manager, [
            new PackageAnnotations('acme/fixture', null, [], [], [], [
                self::diagnostic(),
            ]),
            new PackageAnnotations('other/package', null, [], [], [], [
                $second,
            ]),
        ]);
    }

    /** @throws Throwable When restoration fails */
    public function testDiagnosticNamesAreNeverAutoloadedDuringRestoration(): void
    {
        $row = self::diagnostic();
        $manager = $this->packageManager([
            $this->package('acme/fixture', null),
        ]);
        $cache = new AnnotationCache(new TemporaryVendorDirectory());
        $cache->write($manager, [
            new PackageAnnotations('acme/fixture', null, [], [], [], [
                $row,
            ]),
        ]);
        $requested = [];
        $watch = static function (string $class) use (&$requested): void {
            $requested[] = $class;
        };
        spl_autoload_register($watch, true, true);
        try {
            $restored = $cache->read($manager);
            self::assertInstanceOf(ScanDiagnosticsInterface::class, $restored);
            self::assertSame([
                $row,
            ], $restored->getDiagnostics());
        } finally {
            spl_autoload_unregister($watch);
        }
        self::assertNotContains($row['class'], $requested);
        self::assertNotContains($row['exception_type'], $requested);
    }

    /** @throws Throwable When cache preparation fails */
    public function testPartialRestorationRaisesTheOriginalFailure(): void
    {
        $manager = $this->packageManager([
            $this->package('acme/fixture', null),
            $this->package('z/broken', null),
        ]);
        $vendor = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendor);
        $cache->write($manager, [
            new PackageAnnotations('acme/fixture', null, [], [], [], [
                self::diagnostic(),
            ]),
            new PackageAnnotations('z/broken', null, [], [], []),
        ]);
        $vendor->write('annotations/z/broken.ser', 'corrupt record without readable diagnostics');
        $this->expectException(AnnotationCacheException::class);
        $this->expectExceptionMessageIsOrContains('annotation cache for package z/broken is invalid');
        $cache->read($manager);
    }

    /** @return array{sequence: int, package: string, class: string, exception_type: string, message: string} */
    private static function diagnostic(): array
    {
        return [
            'sequence'       => 1,
            'package'        => 'acme/fixture',
            'class'          => 'Missing\\Subject',
            'exception_type' => 'Missing\\Failure',
            'message'        => '',
        ];
    }
}
