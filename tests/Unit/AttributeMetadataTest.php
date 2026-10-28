<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stringable;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Tests\Fixture\CollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\DerivedCollectedAttribute;
use SuperKernel\Annotator\Tests\Fixture\FixtureMode;
use Throwable;
use UnexpectedValueException;

use function serialize;
use function unserialize;

final class AttributeMetadataTest extends TestCase
{
    public function testExposesTheTrustedInstanceAndUniqueAssignableTypes(): void
    {
        $attribute = new DerivedCollectedAttribute('value', FixtureMode::Ready);
        $metadata = new AttributeMetadata($attribute, [
            CollectedAttribute::class,
            CollectedAttribute::class,
        ]);

        self::assertSame($attribute, $metadata->attribute());
        self::assertSame(DerivedCollectedAttribute::class, $metadata->name());
        self::assertSame([
            DerivedCollectedAttribute::class,
            CollectedAttribute::class,
        ], $metadata->types());
    }

    public function testRestoresTheTrustedInstanceFromTheNarrowOuterCacheAllowList(): void
    {
        $metadata = new AttributeMetadata(
            new CollectedAttribute('value', FixtureMode::Ready),
        );

        $restored = unserialize(serialize($metadata), [
            'allowed_classes' => [
                AttributeMetadata::class,
            ],
        ]);

        self::assertInstanceOf(AttributeMetadata::class, $restored);
        self::assertInstanceOf(CollectedAttribute::class, $restored->attribute());
        self::assertSame('value', $restored->attribute()->value);
        self::assertSame(FixtureMode::Ready, $restored->attribute()->mode);
    }

    public function testRejectsACapturedTypeThatTheInstanceDoesNotImplement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('is not assignable to captured type');

        new AttributeMetadata(new CollectedAttribute('value'), [
            Stringable::class,
        ]);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsANonCanonicalSerializedTypeList(): void
    {
        $metadata = new ReflectionClass(AttributeMetadata::class)->newInstanceWithoutConstructor();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('concrete-first unique type list');

        $metadata->__unserialize([
            'name'      => CollectedAttribute::class,
            'attribute' => serialize(new CollectedAttribute('value')),
            'types'     => [],
        ]);
    }
}
