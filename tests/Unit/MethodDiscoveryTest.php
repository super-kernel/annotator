<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Stringable;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Scan\ClassAnnotationScanner;
use SuperKernel\Annotator\Tests\Fixture\AnnotatedParent;
use SuperKernel\Annotator\Tests\Fixture\CollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\DerivedMethodAttribute;
use SuperKernel\Annotator\Tests\Fixture\MethodAnnotatedFixture;
use SuperKernel\Contract\AttributeMetadataInterface;
use SuperKernel\Contract\PackageAnnotationsInterface;
use SuperKernel\Contract\ReflectorInterface;

use function class_exists;

final class MethodDiscoveryTest extends TestCase
{
    public function testAcceptsIndependentPackageAndMetadataImplementations(): void
    {
        $attribute = new DerivedMethodAttribute('custom');
        $metadata = $this->createStub(AttributeMetadataInterface::class);
        $metadata->method('name')->willReturn(DerivedMethodAttribute::class);
        $metadata->method('types')->willReturn([
            DerivedMethodAttribute::class,
            CollectedAttribute::class,
            Stringable::class,
        ]);
        $metadata->method('attribute')->willReturn($attribute);
        $methods = [
            'Acme\\IndependentMethodOwner' => [
                'handle' => [
                    $metadata,
                ],
            ],
        ];
        $package = $this->createStub(PackageAnnotationsInterface::class);
        $package->method('methodAttributes')->willReturn($methods);
        $package->method('packageName')->willReturn('acme/independent');
        $collector = new AnnotationCollector([
            $package,
        ]);

        self::assertSame($methods, $collector->getMethodsWithAttribute(DerivedMethodAttribute::class));
        self::assertSame([], $collector->getMethodsWithAttribute(CollectedAttribute::class));
        self::assertSame($methods, $collector->getMethodsWithAttributeAssignableTo(Stringable::class));
        self::assertSame($attribute, $metadata->attribute());
        self::assertFalse(class_exists('Acme\\IndependentMethodOwner', false));
    }

    public function testPreservesExactAndAssignableMatchesOrderAndIdentityWithoutLoadingTargets(): void
    {
        $first = new AttributeMetadata(new CollectedAttribute('first'));
        $derived = new AttributeMetadata(new DerivedMethodAttribute('derived'), [
            CollectedAttribute::class,
            Stringable::class,
        ]);
        $second = new AttributeMetadata(new CollectedAttribute('second'));
        $collector = new AnnotationCollector([
            new PackageAnnotations('acme/first', null, [], [
                'Acme\\UnloadedMethodOwner' => [
                    'Handle' => [
                        $first,
                        $derived,
                        $second,
                    ],
                    'another' => [
                        $second,
                    ],
                ],
            ], []),
            new PackageAnnotations('acme/second', null, [], [
                'Acme\\AnotherMethodOwner' => [
                    'create' => [
                        $derived,
                    ],
                ],
            ], []),
        ]);

        self::assertSame([
            'Acme\\UnloadedMethodOwner' => [
                'Handle' => [
                    $first,
                    $second,
                ],
                'another' => [
                    $second,
                ],
            ],
        ], $collector->getMethodsWithAttribute(CollectedAttribute::class));
        self::assertSame([
            'Acme\\UnloadedMethodOwner' => [
                'Handle' => [
                    $first,
                    $derived,
                    $second,
                ],
                'another' => [
                    $second,
                ],
            ],
            'Acme\\AnotherMethodOwner' => [
                'create' => [
                    $derived,
                ],
            ],
        ], $collector->getMethodsWithAttributeAssignableTo(CollectedAttribute::class));
        self::assertSame([
            'Acme\\UnloadedMethodOwner' => [
                'Handle' => [
                    $derived,
                ],
            ],
            'Acme\\AnotherMethodOwner' => [
                'create' => [
                    $derived,
                ],
            ],
        ], $collector->getMethodsWithAttributeAssignableTo(Stringable::class));
        self::assertSame([], $collector->getMethodsWithAttribute(Stringable::class));
        self::assertSame([], $collector->getMethodsWithAttribute('Acme\\UnknownAttribute'));
        self::assertSame(
            [],
            $collector->getMethodsWithAttributeAssignableTo('Acme\\UnknownAttribute'),
        );
        self::assertFalse(class_exists('Acme\\UnloadedMethodOwner', false));
        self::assertFalse(class_exists('Acme\\AnotherMethodOwner', false));
        self::assertFalse(class_exists('Acme\\UnknownAttribute', false));
    }

    public function testKeepsFirstPackageOwnershipBeforeFilteringMethods(): void
    {
        $base = new AttributeMetadata(new CollectedAttribute('base'));
        $derived = new AttributeMetadata(new DerivedMethodAttribute('derived'), [
            CollectedAttribute::class,
        ]);
        $collector = new AnnotationCollector([
            new PackageAnnotations('acme/first', null, [], [
                'Acme\\OwnedMethods' => [
                    'first' => [
                        $base,
                    ],
                ],
                'Acme\\EmptyMethods' => [],
            ], []),
            new PackageAnnotations('acme/second', null, [], [
                'Acme\\OwnedMethods' => [
                    'shadow' => [
                        $derived,
                    ],
                ],
                'Acme\\EmptyMethods' => [
                    'shadow' => [
                        $derived,
                    ],
                ],
                'Acme\\SeparateMethods' => [
                    'last' => [
                        $derived,
                    ],
                ],
            ], []),
        ]);

        self::assertSame([
            'Acme\\SeparateMethods' => [
                'last' => [
                    $derived,
                ],
            ],
        ], $collector->getMethodsWithAttribute(DerivedMethodAttribute::class));
        self::assertSame([
            'Acme\\OwnedMethods' => [
                'first' => [
                    $base,
                ],
            ],
            'Acme\\SeparateMethods' => [
                'last' => [
                    $derived,
                ],
            ],
        ], $collector->getMethodsWithAttributeAssignableTo(CollectedAttribute::class));
    }

    public function testEmptyCollectorsReturnNoMethods(): void
    {
        $collector = new AnnotationCollector([]);

        self::assertSame([], $collector->getMethodsWithAttribute(CollectedAttribute::class));
        self::assertSame(
            [],
            $collector->getMethodsWithAttributeAssignableTo(CollectedAttribute::class),
        );
    }

    /** @throws ReflectionException When the method fixtures cannot be scanned */
    public function testIndexesScannedMethodsUnderTheirDeclaringClassWithoutChangingMemberLookup(): void
    {
        $reflector = $this->createStub(ReflectorInterface::class);
        $reflector->method('reflectClass')->willReturnCallback(
            static fn(object|string $class): ReflectionClass => new ReflectionClass($class),
        );
        $scanner = new ClassAnnotationScanner($reflector);
        $child = $scanner->collect(new ReflectionClass(MethodAnnotatedFixture::class));
        $parent = $scanner->collect(new ReflectionClass(AnnotatedParent::class));
        $collector = new AnnotationCollector([
            new PackageAnnotations('acme/fixture', null, [], $child['methods'] + $parent['methods'], []),
        ]);
        $attributes = $collector->getMethodAttributes(MethodAnnotatedFixture::class, 'handle');

        self::assertCount(3, $attributes);
        self::assertSame([
            MethodAnnotatedFixture::class => [
                'handle' => [
                    $attributes[0],
                    $attributes[2],
                ],
            ],
            AnnotatedParent::class => [
                'inherited' => $collector->getMethodAttributes(
                    AnnotatedParent::class,
                    'inherited',
                ),
            ],
        ], $collector->getMethodsWithAttribute(CollectedAttribute::class));
        self::assertSame($attributes, $collector->getMethodsWithAttributeAssignableTo(
            CollectedAttribute::class,
        )[MethodAnnotatedFixture::class]['handle']);
        self::assertSame([
            MethodAnnotatedFixture::class => [
                'handle' => [
                    $attributes[1],
                ],
            ],
        ], $collector->getMethodsWithAttributeAssignableTo(Stringable::class));
        self::assertSame([], $collector->getMethodAttributes(MethodAnnotatedFixture::class, 'inherited'));
    }

}
