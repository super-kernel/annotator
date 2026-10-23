<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Tests\Fixture\AnnotatedFixture;
use SuperKernel\Annotator\Tests\Fixture\AnnotatedParent;
use SuperKernel\Annotator\Tests\Fixture\CollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\DerivedCollectedAttribute;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AttributeMetadataInterface;
use SuperKernel\Contract\PackageAnnotationsInterface;

final class AnnotationCollectorTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function metadataImplementations(): iterable
    {
        yield 'Annotator models' => [
            false,
        ];
        yield 'independent metadata' => [
            true,
        ];
    }

    #[DataProvider('metadataImplementations')]
    public function testUsesSharedPackageAndMetadataContracts(bool $independent): void
    {
        $attribute = new DerivedCollectedAttribute('shared');
        if ($independent) {
            $metadata = $this->createStub(AttributeMetadataInterface::class);
            $metadata->method('name')->willReturn(DerivedCollectedAttribute::class);
            $metadata->method('attribute')->willReturn($attribute);
            $metadata->method('types')->willReturn([
                DerivedCollectedAttribute::class,
                CollectedAttribute::class,
            ]);
        } else {
            $metadata = new AttributeMetadata($attribute, [
                CollectedAttribute::class,
            ]);
        }
        $classes = [
            AnnotatedFixture::class => [
                $metadata,
            ],
        ];
        $methods = [
            AnnotatedFixture::class => [
                'handle' => [
                    $metadata,
                ],
            ],
        ];
        $properties = [
            AnnotatedFixture::class => [
                'value' => [
                    $metadata,
                ],
            ],
        ];
        if ($independent) {
            $package = $this->createStub(PackageAnnotationsInterface::class);
            $package->method('packageName')->willReturn('acme/fixture');
            $package->method('classAttributes')->willReturn($classes);
            $package->method('methodAttributes')->willReturn($methods);
            $package->method('propertyAttributes')->willReturn($properties);
        } else {
            $package = new PackageAnnotations('acme/fixture', null, $classes, $methods, $properties);
        }
        $collector = new AnnotationCollector([
            $package,
        ]);

        self::assertSame(
            (string) new ReflectionMethod(AnnotationCollectorInterface::class, 'getPackage')->getReturnType(),
            (string) new ReflectionMethod(AnnotationCollector::class, 'getPackage')->getReturnType(),
        );
        self::assertSame($package, $collector->getPackage('ACME/FIXTURE'));
        self::assertNull($collector->getPackage('acme/missing'));
        self::assertSame($package, $collector->getPackages()['acme/fixture']);
        self::assertSame($metadata, $collector->getClassAttributes(AnnotatedFixture::class)[0]);
        self::assertSame($metadata, $collector->getMethodAttributes(AnnotatedFixture::class, 'handle')[0]);
        self::assertSame($metadata, $collector->getPropertyAttributes(AnnotatedFixture::class, 'value')[0]);
        self::assertSame($classes, $collector->getClassesWithAttribute(DerivedCollectedAttribute::class));
        self::assertSame($classes, $collector->getClassesWithAttributeAssignableTo(CollectedAttribute::class));
        self::assertSame([], $collector->getClassesWithAttribute(CollectedAttribute::class));
        self::assertSame($methods, $collector->getMethodsWithAttribute(DerivedCollectedAttribute::class));
        self::assertSame($methods, $collector->getMethodsWithAttributeAssignableTo(CollectedAttribute::class));
        self::assertSame([], $collector->getMethodsWithAttribute(CollectedAttribute::class));
        self::assertSame(
            $attribute,
            $collector->getMethodAttributes(AnnotatedFixture::class, 'handle')[0]->attribute(),
        );
    }

    public function testReturnsClassesAndOnlyMetadataMatchingTheExactAttribute(): void
    {
        $first = new AttributeMetadata(new CollectedAttribute('first'));
        $other = new AttributeMetadata(new DerivedCollectedAttribute('other'), [
            CollectedAttribute::class,
        ]);
        $second = new AttributeMetadata(new CollectedAttribute('second'));
        $repeated = new AttributeMetadata(new CollectedAttribute('repeated'));
        $collector = new AnnotationCollector([
            new PackageAnnotations('acme/fixture', null, [
                AnnotatedFixture::class => [
                    $first,
                    $other,
                ],
                AnnotatedParent::class => [
                    $second,
                    $repeated,
                ],
            ], [], []),
        ]);

        self::assertSame([
            AnnotatedFixture::class => [
                $first,
            ],
            AnnotatedParent::class => [
                $second,
                $repeated,
            ],
        ], $collector->getClassesWithAttribute(CollectedAttribute::class));
        self::assertSame([], $collector->getClassesWithAttribute('Acme\\MissingAttribute'));
    }

    public function testReturnsClassesCarryingAssignableAttributeTypesWithoutChangingExactLookup(): void
    {
        $base = new AttributeMetadata(new CollectedAttribute('base'));
        $derived = new AttributeMetadata(new DerivedCollectedAttribute('derived'), [
            DerivedCollectedAttribute::class,
            CollectedAttribute::class,
        ]);
        $collector = new AnnotationCollector([
            new PackageAnnotations('acme/fixture', null, [
                AnnotatedFixture::class => [
                    $derived,
                ],
                AnnotatedParent::class => [
                    $base,
                ],
            ], [], []),
        ]);

        self::assertSame([
            AnnotatedFixture::class => [
                $derived,
            ],
            AnnotatedParent::class => [
                $base,
            ],
        ], $collector->getClassesWithAttributeAssignableTo(CollectedAttribute::class));
        self::assertSame([
            AnnotatedParent::class => [
                $base,
            ],
        ], $collector->getClassesWithAttribute(CollectedAttribute::class));
    }

    public function testKeepsConcreteAndAssignableAttributeTypesUnique(): void
    {
        $metadata = new AttributeMetadata(new DerivedCollectedAttribute('derived'), [
            CollectedAttribute::class,
            DerivedCollectedAttribute::class,
            CollectedAttribute::class,
        ]);

        self::assertSame([
            DerivedCollectedAttribute::class,
            CollectedAttribute::class,
        ], $metadata->types());
        self::assertSame('derived', $metadata->attribute()->value);
    }
}
