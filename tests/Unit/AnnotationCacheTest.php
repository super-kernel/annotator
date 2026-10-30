<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Cache\SerializedPackageAnnotations;
use SuperKernel\Annotator\Cache\SerializedPackageReferences;
use SuperKernel\Annotator\Constants\CacheLayout;
use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Provider\AnnotationCollectorProvider;
use SuperKernel\Annotator\Tests\Fixture\CollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\FixtureMode;
use SuperKernel\Annotator\Tests\Support\CallbackScanProcessor;
use SuperKernel\Annotator\Tests\Support\TemporaryVendorDirectory;
use SuperKernel\Annotator\Tests\Support\TestCase;
use Throwable;

use function array_keys;
use function count;
use function file_get_contents;
use function glob;
use function mkdir;
use function serialize;
use function sprintf;
use function str_replace;
use function strlen;
use function symlink;
use function unlink;

final class AnnotationCacheTest extends TestCase
{
    /** @throws Throwable When fixture setup or legacy cache restoration fails */
    public function testRejectsSchemaThreeCacheWithoutDiagnostics(): void
    {
        $contents = file_get_contents(__DIR__ . '/../Fixture/legacy-package-annotations.ser');
        self::assertNotFalse($contents);
        $manager = $this->packageManager([
            $this->package('acme/legacy', 'before-metadata-contracts'),
        ]);
        $vendorDir = new TemporaryVendorDirectory();
        $vendorDir->write('annotations/acme/legacy.ser', $contents);
        $vendorDir->write(CacheLayout::IndexPath->value, serialize(new SerializedPackageReferences([
            'acme/legacy' => 'before-metadata-contracts',
        ])));

        $this->expectException(AnnotationCacheException::class);
        $this->expectExceptionMessageIsOrContains('annotation cache for package acme/legacy is invalid');
        new AnnotationCache($vendorDir)->read($manager);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testWritesAndRestoresRawPerPackageRecordsAndReferenceStub(): void
    {
        $package = $this->package('acme/fixture', 'reference-a');
        $manager = $this->packageManager([
            $package,
        ]);
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $record = self::record('acme/fixture', 'reference-a');

        $cache->write($manager, [
            $record,
        ]);

        self::assertArrayHasKey('packages.ser', $vendorDir->files());
        self::assertStringNotContainsString(
            'schemaVersion',
            $vendorDir->read(CacheLayout::IndexPath->value),
        );
        self::assertArrayHasKey(
            'annotations/acme/fixture.ser',
            $vendorDir->files(),
        );
        $collector = $cache->read($manager);
        self::assertSame($record->packageName(), $collector->getPackage('ACME/FIXTURE')?->packageName());
        self::assertSame(
            CollectedAttribute::class,
            $collector->getClassAttributes('Acme\\Subject')[0]->name(),
        );
        self::assertSame(
            [
                CollectedAttribute::class,
            ],
            $collector->getClassAttributes('Acme\\Subject')[0]->types(),
        );
        $attribute = $collector->getMethodAttributes('Acme\\Subject', 'handle')[0]->attribute();
        self::assertSame('value', $attribute->value);
        self::assertSame(FixtureMode::Ready, $attribute->mode);
        self::assertSame(
            $attribute,
            $collector->getMethodsWithAttribute(CollectedAttribute::class)['Acme\\Subject']['handle'][0]->attribute(),
        );
        self::assertSame(
            $collector->getMethodsWithAttribute(CollectedAttribute::class),
            $collector->getMethodsWithAttributeAssignableTo(CollectedAttribute::class),
        );
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testInvalidationPreservesTheComposerClassCache(): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $vendorDir->write('classes/acme/fixture.ser', 'composer cache');
        $cache = new AnnotationCache($vendorDir);
        $cache->write($this->packageManager([
            $this->package('acme/fixture', null),
        ]), [
            self::record('acme/fixture', null),
        ]);

        $cache->invalidate();

        self::assertSame([
            'classes/acme/fixture.ser' => 'composer cache',
        ], $vendorDir->files());
        self::assertDirectoryDoesNotExist($vendorDir . '/.cache/annotations');
        self::assertDirectoryDoesNotExist($vendorDir . '/.runtime');
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testFailedPublicationPreservesTheReferenceStubAndRemovesTemporaryFiles(): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $manager = $this->packageManager([
            $this->package('acme/fixture', null),
        ]);
        $records = [
            self::record('acme/fixture', null),
        ];
        $cache->write($manager, $records);
        $original = $vendorDir->read('packages.ser');
        $path = $vendorDir . '/.cache/annotations/acme/fixture.ser';
        self::assertTrue(unlink($path));
        self::assertTrue(mkdir($path));

        try {
            $cache->write($manager, $records);
            self::fail('A cache file must not replace a directory.');
        } catch (AnnotationCacheException $exception) {
            self::assertStringContainsString('Unable to publish', $exception->getMessage());
        }

        self::assertSame($original, $vendorDir->read('packages.ser'));
        self::assertSame([], glob($vendorDir . '/.cache/annotations/acme/.annotations-*'));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsACacheDirectorySymlinkWithoutWritingThroughIt(): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $target = new TemporaryVendorDirectory();
        self::assertTrue(symlink((string) $target, $vendorDir . '/.cache'));
        $cache = new AnnotationCache($vendorDir);

        try {
            $cache->write($this->packageManager([
                $this->package('acme/fixture', null),
            ]), [
                self::record('acme/fixture', null),
            ]);
            self::fail('A linked cache directory must not be written.');
        } catch (AnnotationCacheException $exception) {
            self::assertStringContainsString('not a regular directory', $exception->getMessage());
        }

        self::assertFileDoesNotExist($target . '/packages.ser');
        self::assertDirectoryDoesNotExist($target . '/annotations');
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAReferenceStubFromDifferentComposerMetadata(): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $initial = $this->packageManager([
            $this->package('acme/fixture', 'reference-a'),
        ]);
        $cache->write($initial, [
            self::record('acme/fixture', 'reference-a'),
        ]);
        $changed = $this->packageManager([
            $this->package('acme/fixture', 'reference-b'),
        ]);

        $this->expectException(AnnotationCacheException::class);
        $this->expectExceptionMessageIsOrContains('does not match current Composer metadata');

        $cache->read($changed);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAnAttributeInstanceCacheFromThePreviousSchema(): void
    {
        $package = $this->package('acme/fixture', 'reference-a');
        $manager = $this->packageManager([
            $package,
        ]);
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $cache->write($manager, [
            self::record('acme/fixture', 'reference-a'),
        ]);
        $path = 'annotations/acme/fixture.ser';
        $replacementCount = 0;
        $stale = str_replace(
            'schemaVersion";i:4;',
            'schemaVersion";i:3;',
            $vendorDir->read($path),
            $replacementCount,
        );
        self::assertSame(1, $replacementCount);
        $vendorDir->write($path, $stale);

        $this->expectException(AnnotationCacheException::class);
        $this->expectExceptionMessageIsOrContains('annotation cache for package acme/fixture is invalid');

        $cache->read($manager);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAndReplacesAnInvalidReferenceStubOnTheNextWrite(): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $vendorDir->write('packages.ser', 'not-serialized');
        $vendorDir->write('annotations/orphan/package.ser', 'orphan');
        $cache = new AnnotationCache($vendorDir);
        $manager = $this->packageManager([
            $this->package('acme/fixture', null),
        ]);

        $cache->write($manager, [
            self::record('acme/fixture', null),
        ]);

        self::assertFalse($vendorDir->exists('annotations/orphan/package.ser'));
        self::assertSame(
            'acme/fixture',
            $cache->read($manager)->getPackages()['acme/fixture']->packageName(),
        );
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('legacyCacheEntries')]
    public function testRejectsLegacyNamespacesAndRebuildsTheirCacheDuringANormalScan(
        string $path,
        string $legacyClass,
        array  $properties,
    ): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $manager = $this->packageManager([
            $this->package('acme/fixture', null),
        ]);
        $cache->write($manager, [
            self::record('acme/fixture', null),
        ]);
        $vendorDir->write('classes/acme/fixture.ser', 'composer cache');
        $serializedProperties = '';

        foreach ($properties as $name => $value) {
            $serializedProperties .= serialize("\0" . $legacyClass . "\0" . $name) . serialize($value);
        }

        $vendorDir->write($path, sprintf(
            'O:%d:"%s":%d:{%s}',
            strlen($legacyClass),
            $legacyClass,
            count($properties),
            $serializedProperties,
        ));

        try {
            $cache->read($manager);
            self::fail('A cache record from the former namespace must require a normal rescan.');
        } catch (AnnotationCacheException $exception) {
            self::assertStringContainsString('invalid', $exception->getMessage());
        }

        $collector = new AnnotationCollectorProvider()(
            $manager,
            new CallbackScanProcessor(),
            $this->reflector(),
            $vendorDir,
        );

        self::assertSame([
            'acme/fixture',
        ], array_keys($collector->getPackages()));
        self::assertStringContainsString(
            str_replace('\\Internal\\', '\\Cache\\', $legacyClass),
            $vendorDir->read($path),
        );
        self::assertSame('composer cache', $vendorDir->read('classes/acme/fixture.ser'));
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function legacyCacheEntries(): iterable
    {
        yield 'reference stub' => [
            CacheLayout::IndexPath->value,
            'SuperKernel\\Annotator\\Internal\\SerializedPackageReferences',
            [
                'references' => [
                    'acme/fixture' => null,
                ],
            ],
        ];
        yield 'package record' => [
            'annotations/acme/fixture.ser',
            'SuperKernel\\Annotator\\Internal\\SerializedPackageAnnotations',
            [
                'schemaVersion' => 3,
                'annotations'   => new PackageAnnotations('acme/fixture', null, [], [], []),
            ],
        ];
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAnIncompleteReferenceStubAsInvalidCacheData(): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $vendorDir->write(
            CacheLayout::IndexPath->value,
            serialize(new ReflectionClass(SerializedPackageReferences::class)->newInstanceWithoutConstructor()),
        );

        $this->expectException(AnnotationCacheException::class);
        $this->expectExceptionMessageIsOrContains('annotation package-reference stub cannot be decoded');

        new AnnotationCache($vendorDir)->read($this->packageManager([]));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAnIncompletePackageRecordAsInvalidCacheData(): void
    {
        $manager = $this->packageManager([
            $this->package('acme/fixture', null),
        ]);
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $cache->write($manager, [
            self::record('acme/fixture', null),
        ]);
        $vendorDir->write(
            'annotations/acme/fixture.ser',
            serialize(new ReflectionClass(SerializedPackageAnnotations::class)->newInstanceWithoutConstructor()),
        );

        $this->expectException(AnnotationCacheException::class);
        $this->expectExceptionMessageIsOrContains('annotation cache for package acme/fixture cannot be decoded');

        $cache->read($manager);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testDeletesARecordRemovedFromTheReferenceStub(): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $cache = new AnnotationCache($vendorDir);
        $first = $this->package('acme/first', 'first');
        $second = $this->package('acme/second', 'second');
        $cache->write(
            $this->packageManager([
                $first,
                $second,
            ]),
            [
                self::record('acme/first', 'first'),
                self::record('acme/second', 'second'),
            ],
        );

        $cache->write(
            $this->packageManager([
                $first,
            ]),
            [
                self::record('acme/first', 'first'),
            ],
        );

        self::assertFalse($vendorDir->exists('annotations/acme/second.ser'));
    }

    private static function record(string $packageName, ?string $reference): PackageAnnotations
    {
        $attribute = new AttributeMetadata(new CollectedAttribute('value', FixtureMode::Ready));

        return new PackageAnnotations(
            $packageName,
            $reference,
            [
                'Acme\\Subject' => [
                    $attribute,
                ],
            ],
            [
                'Acme\\Subject' => [
                    'handle' => [
                        $attribute,
                    ],
                ],
            ],
            [
                'Acme\\Subject' => [
                    'value' => [
                        $attribute,
                    ],
                ],
            ],
        );
    }
}
