<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stringable;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Tests\Fixture\CollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\DerivedMethodAttribute;
use SuperKernel\Contract\AttributeMetadataInterface;
use SuperKernel\Contract\PackageAnnotationsInterface;

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

    public function testIndexesMethodsUnderTheirDeclaringClassWithoutChangingMemberLookup(): void
    {
        $collector = new AnnotationCollector([
            new PackageAnnotations('acme/fixture', null, [], [
                'Acme\\AnnotatedChild' => [
                    'handle' => [
                        new AttributeMetadata(new CollectedAttribute('first')),
                        new AttributeMetadata(new DerivedMethodAttribute('derived'), [
                            CollectedAttribute::class,
                            Stringable::class,
                        ]),
                        new AttributeMetadata(new CollectedAttribute('second')),
                    ],
                ],
                'Acme\\AnnotatedParent' => [
                    'inherited' => [
                        new AttributeMetadata(new CollectedAttribute('inherited-method')),
                    ],
                ],
            ], []),
        ]);
        $attributes = $collector->getMethodAttributes('Acme\\AnnotatedChild', 'handle');

        self::assertCount(3, $attributes);
        self::assertSame([
            'Acme\\AnnotatedChild' => [
                'handle' => [
                    $attributes[0],
                    $attributes[2],
                ],
            ],
            'Acme\\AnnotatedParent' => [
                'inherited' => $collector->getMethodAttributes(
                    'Acme\\AnnotatedParent',
                    'inherited',
                ),
            ],
        ], $collector->getMethodsWithAttribute(CollectedAttribute::class));
        self::assertSame($attributes, $collector->getMethodsWithAttributeAssignableTo(
            CollectedAttribute::class,
        )['Acme\\AnnotatedChild']['handle']);
        self::assertSame([
            'Acme\\AnnotatedChild' => [
                'handle' => [
                    $attributes[1],
                ],
            ],
        ], $collector->getMethodsWithAttributeAssignableTo(Stringable::class));
        self::assertSame([], $collector->getMethodAttributes('Acme\\AnnotatedChild', 'inherited'));
    }

}
