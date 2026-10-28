<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Cache\SerializedPackageAnnotations;
use SuperKernel\Annotator\Cache\SerializedPackageReferences;
use SuperKernel\Annotator\Diagnostic\DiagnosticOutput;
use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Scan\AnnotationScanner;
use SuperKernel\Annotator\Tests\Support\TemporaryVendorDirectory;
use SuperKernel\Annotator\Tests\Support\TestCase;
use SuperKernel\Contract\PackageAnnotationsInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Contract\ScanDiagnosticsInterface;
use Throwable;

use function array_column;
use function array_keys;
use function class_exists;
use function serialize;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_repeat;

final class ScanDiagnosticsTest extends TestCase
{
    /** @throws Throwable When fixture setup or scanning fails */
    public function testStructuredReporterPersistsOriginalBytesAndScanOrderAcrossSortedPackages(): void
    {
        $z = $this->package('z/root', null);
        $a = $this->package('a/dependency', null);
        $manager = $this->packageManager([
            $z,
            $a,
        ], [
            'Unloaded\\Root'       => __DIR__ . '/../Fixture/AnnotatedFixture.php',
            'Unloaded\\Dependency' => __DIR__ . '/../Fixture/AnnotatedFixture.php',
        ], [
            'Unloaded\\Root'       => $z,
            'Unloaded\\Dependency' => $a,
        ]);
        $failure = new class("\x00\n\r\t\033\xff" . str_repeat('x', 8192)) extends RuntimeException {};
        $reflector = $this->createStub(ReflectorInterface::class);
        $reflector->method('reflectClass')->willThrowException($failure);
        $captured = [];
        $scanner = new AnnotationScanner($manager, $reflector, diagnosticReporter: static function (
            array $row,
        ) use (&$captured): void {
            $captured[] = $row;
        });
        $records = $scanner->scan();
        $collector = new AnnotationCollector($records);
        self::assertSame([
            'a/dependency',
            'z/root',
        ], array_keys($collector->getPackages()));
        self::assertSame($captured, $collector->getDiagnostics());
        self::assertSame([
            1,
            2,
        ], array_column($captured, 'sequence'));
        self::assertSame([
            'Unloaded\\Root',
            'Unloaded\\Dependency',
        ], array_column($captured, 'class'));
        self::assertSame($failure::class, $captured[0]['exception_type']);
        self::assertSame($failure->getMessage(), $captured[0]['message']);
        self::assertSame([], $collector->getClassAttributes('Unloaded\\Root'));
        $cache = new AnnotationCache(new TemporaryVendorDirectory());
        $cache->write($manager, $records);
        $restored = $cache->read($manager);
        self::assertInstanceOf(ScanDiagnosticsInterface::class, $restored);
        self::assertSame($captured, $restored->getDiagnostics());
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

    public function testEmergencyFormatPreservesEveryLegacyByte(): void
    {
        $row = self::diagnostic();
        $row['message'] = "message.\n\033\x00\xff";
        self::assertSame(
            "\033[33mAnnotation scan warning: Skipping class Missing\\Subject because Missing\\Failure was thrown: "
            . $row['message'] . ".\033[0m",
            DiagnosticOutput::formatWarning($row),
        );
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
    public function testPartialRestorationKeepsTheOriginalFailureAfterFlushingOnlyValidatedRows(): void
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
