<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use ReflectionClass;
use RuntimeException;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Scan\AnnotationScanner;
use SuperKernel\Annotator\Tests\Fixture\AnnotatedFixture;
use SuperKernel\Annotator\Tests\Fixture\CollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\DerivedCollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\FixtureMode;
use SuperKernel\Annotator\Tests\Fixture\InvalidAnnotatedFixture;
use SuperKernel\Annotator\Tests\Fixture\RepeatedAnnotatedFixture;
use SuperKernel\Annotator\Tests\Fixture\UnserializableAnnotatedFixture;
use SuperKernel\Annotator\Tests\Support\TestCase;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\ReflectorInterface;
use Throwable;

use function array_map;
use function dirname;

final class AnnotationScannerTest extends TestCase
{
    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRequestsEachPackageClassMapWithoutAggregateOwnershipLookup(): void
    {
        $first = $this->package('acme/first', 'first-reference');
        $second = $this->package('acme/second', 'second-reference');
        $class = AnnotatedFixture::class;
        $manager = $this->createMock(PackageManagerInterface::class);
        $manager->method('all')->willReturn([
            $first,
            $second,
        ]);
        $manager->expects(self::exactly(2))->method('classes')->willReturnMap([
            [
                'acme/first',
                [
                    $class => dirname(__DIR__) . '/Fixture/AnnotatedFixture.php',
                ],
            ],
            [
                'acme/second',
                [],
            ],
        ]);
        $manager->expects(self::never())->method('packageForClass');

        $records = new AnnotationScanner($manager, $this->reflector())->scan();

        self::assertSame([
            'acme/first',
            'acme/second',
        ], array_map(
            static fn($record): string => $record->packageName(),
            $records,
        ));
        self::assertArrayHasKey($class, $records[0]->classAttributes());
        self::assertSame([], $records[1]->classAttributes());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCollectsClassMethodAndPropertyAttributesByOwningPackage(): void
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

        $records = new AnnotationScanner($manager, $this->reflector())->scan();

        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame('acme/fixture', $record->packageName());
        self::assertSame('fixture-reference', $record->reference());
        $classAttributes = $record->classAttributes()[$class];
        self::assertSame(
            [
                CollectedAttribute::class,
                CollectedAttribute::class,
                DerivedCollectedAttribute::class,
            ],
            array_map(static fn(AttributeMetadata $attribute): string => $attribute->name(), $classAttributes),
        );
        self::assertSame('class', $classAttributes[0]->attribute()->value);
        self::assertSame(FixtureMode::Ready, $classAttributes[0]->attribute()->mode);
        self::assertSame([
            DerivedCollectedAttribute::class,
            CollectedAttribute::class,
        ], $classAttributes[2]->types());
        self::assertSame(
            CollectedAttribute::class,
            $record->methodAttributes()[$class]['handle'][0]->name(),
        );
        self::assertSame('method', $record->methodAttributes()[$class]['handle'][0]->attribute()->value);
        self::assertArrayNotHasKey('inherited', $record->methodAttributes()[$class]);
        self::assertSame(
            CollectedAttribute::class,
            $record->propertyAttributes()[$class]['value'][0]->name(),
        );
        self::assertSame('property', $record->propertyAttributes()[$class]['value'][0]->attribute()->value);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testSkipsAClassAndReportsTheConcreteReflectionFailure(): void
    {
        $class = 'Acme\\BrokenSubject';
        $package = $this->package('acme/fixture', 'fixture-reference');
        $fixturePath = dirname(__DIR__) . '/Fixture/AnnotatedFixture.php';
        $manager = $this->packageManager(
            [
                $package,
            ],
            [
                $class                  => $fixturePath,
                AnnotatedFixture::class => $fixturePath,
            ],
            [
                $class                  => $package,
                AnnotatedFixture::class => $package,
            ],
        );
        $failure = new RuntimeException('concrete reflection failure');
        $reflector = $this->createStub(ReflectorInterface::class);
        $reflector->method('reflectClass')->willReturnCallback(
            static fn(object|string $candidate) => $class === $candidate
                ? throw $failure
                : new ReflectionClass($candidate),
        );
        $diagnostics = [];
        $scanner = new AnnotationScanner(
            $manager,
            $reflector,
            static function (array $diagnostic) use (&$diagnostics): void {
                $diagnostics[] = $diagnostic;
            },
        );

        $records = $scanner->scan();

        self::assertCount(1, $records);
        self::assertArrayNotHasKey($class, $records[0]->classAttributes());
        self::assertArrayHasKey(AnnotatedFixture::class, $records[0]->classAttributes());
        self::assertSame([
            [
                'sequence'       => 1,
                'package'        => $package->name(),
                'class'          => 'Acme\\BrokenSubject',
                'exception_type' => RuntimeException::class,
                'message'        => 'concrete reflection failure',
            ],
        ], $diagnostics);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testSkipsAClassWhoseAttributeCannotBeInstantiated(): void
    {
        $invalid = InvalidAnnotatedFixture::class;
        $valid = AnnotatedFixture::class;
        $package = $this->package('acme/fixture', 'fixture-reference');
        $manager = $this->packageManager(
            [
                $package,
            ],
            [
                $invalid => dirname(__DIR__) . '/Fixture/InvalidAnnotatedFixture.php',
                $valid   => dirname(__DIR__) . '/Fixture/AnnotatedFixture.php',
            ],
            [
                $invalid => $package,
                $valid   => $package,
            ],
        );
        $diagnostics = [];
        $scanner = new AnnotationScanner(
            $manager,
            $this->reflector(),
            static function (array $diagnostic) use (&$diagnostics): void {
                $diagnostics[] = $diagnostic;
            },
        );

        $records = $scanner->scan();

        self::assertArrayNotHasKey($invalid, $records[0]->classAttributes());
        self::assertArrayHasKey($valid, $records[0]->classAttributes());
        self::assertCount(1, $diagnostics);
        self::assertStringContainsString('attribute construction failure', $diagnostics[0]['message']);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testSkipsAClassWhoseAttributeCannotBeSerialized(): void
    {
        $invalid = UnserializableAnnotatedFixture::class;
        $valid = AnnotatedFixture::class;
        $package = $this->package('acme/fixture', 'fixture-reference');
        $manager = $this->packageManager(
            [
                $package,
            ],
            [
                $invalid => dirname(__DIR__) . '/Fixture/UnserializableAnnotatedFixture.php',
                $valid   => dirname(__DIR__) . '/Fixture/AnnotatedFixture.php',
            ],
            [
                $invalid => $package,
                $valid   => $package,
            ],
        );
        $diagnostics = [];
        $scanner = new AnnotationScanner(
            $manager,
            $this->reflector(),
            static function (array $diagnostic) use (&$diagnostics): void {
                $diagnostics[] = $diagnostic;
            },
        );

        $records = $scanner->scan();

        self::assertArrayNotHasKey($invalid, $records[0]->classAttributes());
        self::assertArrayHasKey($valid, $records[0]->classAttributes());
        self::assertCount(1, $diagnostics);
        self::assertStringContainsString(
            'must support trusted serialization and restoration',
            $diagnostics[0]['message'],
        );
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testSkipsAClassThatRepeatsANonRepeatableAttribute(): void
    {
        $invalid = RepeatedAnnotatedFixture::class;
        $valid = AnnotatedFixture::class;
        $package = $this->package('acme/fixture', 'fixture-reference');
        $manager = $this->packageManager(
            [
                $package,
            ],
            [
                $invalid => dirname(__DIR__) . '/Fixture/RepeatedAnnotatedFixture.php',
                $valid   => dirname(__DIR__) . '/Fixture/AnnotatedFixture.php',
            ],
            [
                $invalid => $package,
                $valid   => $package,
            ],
        );
        $diagnostics = [];
        $scanner = new AnnotationScanner(
            $manager,
            $this->reflector(),
            static function (array $diagnostic) use (&$diagnostics): void {
                $diagnostics[] = $diagnostic;
            },
        );

        $records = $scanner->scan();

        self::assertArrayNotHasKey($invalid, $records[0]->classAttributes());
        self::assertArrayHasKey($valid, $records[0]->classAttributes());
        self::assertCount(1, $diagnostics);
        self::assertStringContainsString('must not be repeated', $diagnostics[0]['message']);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testSkipsAClassWhenAssignableTypeCaptureFails(): void
    {
        $class = AnnotatedFixture::class;
        $package = $this->package('acme/fixture', 'fixture-reference');
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
        $reflector = $this->createStub(ReflectorInterface::class);
        $reflector->method('reflectClass')->willReturnCallback(
            static fn(object|string $candidate) => CollectedAttribute::class === $candidate
                ? throw new RuntimeException('attribute type reflection failure')
                : new ReflectionClass($candidate),
        );
        $diagnostics = [];
        $scanner = new AnnotationScanner(
            $manager,
            $reflector,
            static function (array $diagnostic) use (&$diagnostics): void {
                $diagnostics[] = $diagnostic;
            },
        );

        $records = $scanner->scan();

        self::assertSame([], $records[0]->classAttributes());
        self::assertCount(1, $diagnostics);
        self::assertStringContainsString('attribute type reflection failure', $diagnostics[0]['message']);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testDoesNotReflectAClassWhoseSourceContainsNoAttributes(): void
    {
        $class = 'Acme\\UnannotatedSubject';
        $package = $this->package('acme/fixture', 'fixture-reference');
        $manager = $this->packageManager(
            [
                $package,
            ],
            [
                $class => __FILE__,
            ],
            [
                $class => $package,
            ],
        );
        $reflector = $this->createMock(ReflectorInterface::class);
        $reflector->expects(self::never())->method('reflectClass');

        $records = new AnnotationScanner($manager, $reflector)->scan();

        self::assertCount(1, $records);
        self::assertSame([], $records[0]->classAttributes());
        self::assertSame([], $records[0]->methodAttributes());
        self::assertSame([], $records[0]->propertyAttributes());
    }
}
