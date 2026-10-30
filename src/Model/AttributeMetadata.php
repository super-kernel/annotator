<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Model;

use InvalidArgumentException;
use SuperKernel\Annotator\Snapshot\AttributeSnapshotRestorer;
use SuperKernel\Annotator\Snapshot\AttributeTypeList;
use SuperKernel\Contract\AttributeMetadataInterface;
use Throwable;
use UnexpectedValueException;

use function is_array;
use function is_string;
use function serialize;
use function sprintf;

/**
 * @template T of object
 *
 * @implements AttributeMetadataInterface<T>
 */
final readonly class AttributeMetadata implements AttributeMetadataInterface
{
    /** @var T */
    private object $attribute;

    private string $serializedAttribute;

    /** @var non-empty-list<class-string> */
    private array $types;

    /**
     * @param T                  $attribute
     * @param list<class-string> $types     Attribute class, parent classes, and implemented interfaces
     */
    public function __construct(object $attribute, array $types = [])
    {
        $name = $attribute::class;

        try {
            $serialized = serialize($attribute);
            AttributeSnapshotRestorer::restore($serialized, $name);
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException(sprintf(
                'Attribute %s must support trusted serialization and restoration.',
                $name,
            ), 0, $throwable);
        }

        $this->attribute = $attribute;
        $this->serializedAttribute = $serialized;
        $this->types = AttributeTypeList::normalize($attribute, $types);
    }

    /**
     * @return class-string<T>
     */
    public function name(): string
    {
        return $this->attribute::class;
    }

    /**
     * @return T
     */
    public function attribute(): object
    {
        return $this->attribute;
    }

    /**
     * Returns the concrete Attribute type followed by its captured assignable types.
     *
     * @return non-empty-list<class-string>
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * @return array{
     *     name: non-empty-string,
     *     attribute: non-empty-string,
     *     types: non-empty-list<class-string>
     * }
     */
    public function __serialize(): array
    {
        return [
            'name'      => $this->attribute::class,
            'attribute' => $this->serializedAttribute,
            'types'     => $this->types,
        ];
    }

    public function __unserialize(array $data): void
    {
        $name = $data['name'] ?? null;
        $serialized = $data['attribute'] ?? null;
        $types = $data['types'] ?? null;

        if (!is_string($name) || '' === $name || !is_string($serialized) || '' === $serialized || !is_array($types)) {
            throw new UnexpectedValueException('Serialized Attribute metadata is invalid.');
        }

        $attribute = AttributeSnapshotRestorer::restore($serialized, $name);
        $normalizedTypes = AttributeTypeList::normalize($attribute, $types);

        if ($types !== $normalizedTypes) {
            throw new UnexpectedValueException(
                'Serialized Attribute metadata must contain a concrete-first unique type list.',
            );
        }

        $this->attribute = $attribute;
        $this->serializedAttribute = $serialized;
        $this->types = $normalizedTypes;
    }
}
