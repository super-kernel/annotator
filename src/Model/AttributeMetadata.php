<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Model;

use __PHP_Incomplete_Class;
use InvalidArgumentException;
use SplObjectStorage;
use SuperKernel\Contract\AttributeMetadataInterface;
use Throwable;
use UnexpectedValueException;

use function array_unique;
use function array_values;
use function get_debug_type;
use function in_array;
use function is_a;
use function is_array;
use function is_object;
use function is_string;
use function serialize;
use function sprintf;
use function unserialize;

/**
 * @template T of object
 *
 * @implements AttributeMetadataInterface<T>
 */
final readonly class AttributeMetadata implements AttributeMetadataInterface
{
    private const int MAX_OBJECT_GRAPH_DEPTH = 64;

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
            self::restoreAttribute($serialized, $name);
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException(sprintf(
                'Attribute %s must support trusted serialization and restoration.',
                $name,
            ), 0, $throwable);
        }

        $this->attribute = $attribute;
        $this->serializedAttribute = $serialized;
        $this->types = self::normalizeTypes($attribute, $types);
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
            'name'      => $this->name(),
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

        $attribute = self::restoreAttribute($serialized, $name);
        $normalizedTypes = self::normalizeTypes($attribute, $types);

        if ($types !== $normalizedTypes) {
            throw new UnexpectedValueException(
                'Serialized Attribute metadata must contain a concrete-first unique type list.',
            );
        }

        $this->attribute = $attribute;
        $this->serializedAttribute = $serialized;
        $this->types = $normalizedTypes;
    }

    /**
     * @param list<class-string> $types
     *
     * @return non-empty-list<class-string>
     */
    private static function normalizeTypes(object $attribute, array $types): array
    {
        $name = $attribute::class;
        $normalized = array_values(array_unique([
            $name,
            ...$types,
        ]));

        foreach ($normalized as $type) {
            if (!is_string($type) || '' === $type || !is_a($attribute, $type)) {
                throw new InvalidArgumentException(sprintf(
                    'Attribute %s is not assignable to captured type %s.',
                    $name,
                    is_string($type) ? $type : get_debug_type($type),
                ));
            }
        }

        return $normalized;
    }

    private static function restoreAttribute(string $serialized, string $name): object
    {
        $incomplete = @unserialize($serialized, [
            'allowed_classes' => [],
        ]);

        if (!is_object($incomplete)) {
            throw new UnexpectedValueException(sprintf(
                'Serialized Attribute %s does not contain an object.',
                $name,
            ));
        }

        $classes = self::objectClasses($incomplete, new SplObjectStorage());

        if (!in_array($name, $classes, true)) {
            throw new UnexpectedValueException(sprintf(
                'Serialized Attribute payload does not contain expected class %s.',
                $name,
            ));
        }

        $attribute = @unserialize($serialized, [
            'allowed_classes' => $classes,
        ]);

        if (!is_object($attribute) || $name !== $attribute::class) {
            throw new UnexpectedValueException(sprintf(
                'Serialized Attribute payload did not restore expected class %s.',
                $name,
            ));
        }

        self::assertComplete($attribute, new SplObjectStorage());

        return $attribute;
    }

    /**
     * @param SplObjectStorage<object, null> $seen
     *
     * @return list<class-string>
     */
    private static function objectClasses(mixed $value, SplObjectStorage $seen, int $depth = 0): array
    {
        self::assertDepth($depth);

        if (is_array($value)) {
            $classes = [];

            foreach ($value as $entry) {
                $classes = [
                    ...$classes,
                    ...self::objectClasses($entry, $seen, $depth + 1),
                ];
            }

            return array_values(array_unique($classes));
        }

        if (!is_object($value) || $seen->contains($value)) {
            return [];
        }

        $seen->attach($value);
        $properties = (array) $value;
        $name = $value instanceof __PHP_Incomplete_Class
            ? ($properties['__PHP_Incomplete_Class_Name'] ?? null)
            : $value::class;

        if (!is_string($name) || '' === $name) {
            throw new UnexpectedValueException('Serialized Attribute payload contains an unnamed object.');
        }

        $classes = [
            $name,
        ];

        foreach ($properties as $property => $entry) {
            if ('__PHP_Incomplete_Class_Name' === $property) {
                continue;
            }

            $classes = [
                ...$classes,
                ...self::objectClasses($entry, $seen, $depth + 1),
            ];
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param SplObjectStorage<object, null> $seen
     */
    private static function assertComplete(mixed $value, SplObjectStorage $seen, int $depth = 0): void
    {
        self::assertDepth($depth);

        if (is_array($value)) {
            foreach ($value as $entry) {
                self::assertComplete($entry, $seen, $depth + 1);
            }

            return;
        }

        if (!is_object($value) || $seen->contains($value)) {
            return;
        }

        if ($value instanceof __PHP_Incomplete_Class) {
            throw new UnexpectedValueException('Serialized Attribute payload contains an incomplete object.');
        }

        $seen->attach($value);

        foreach ((array) $value as $entry) {
            self::assertComplete($entry, $seen, $depth + 1);
        }
    }

    private static function assertDepth(int $depth): void
    {
        if (self::MAX_OBJECT_GRAPH_DEPTH < $depth) {
            throw new UnexpectedValueException('Serialized Attribute object graph exceeds the supported depth.');
        }
    }
}
