<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Snapshot;

use __PHP_Incomplete_Class;
use SplObjectStorage;
use UnexpectedValueException;

use function array_unique;
use function array_values;
use function is_array;
use function is_object;
use function is_string;

/**
 * Traverses serialized Attribute object graphs within the supported nesting depth.
 *
 * @internal
 */
final class AttributeSnapshotGraph
{
    /**
     * Collects the class names of every reachable object, including names held by incomplete-class placeholders.
     *
     * @param SplObjectStorage<object, null> $seen
     *
     * @return list<class-string>
     *
     * @throws UnexpectedValueException When an object has no class name or the graph exceeds 64 nested levels
     */
    public static function objectClasses(mixed $value, SplObjectStorage $seen, int $depth = 0): array
    {
        AttributeSnapshotDepth::assertWithinLimit($depth);

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
     * Rejects incomplete-class placeholders anywhere in a restored value.
     *
     * @param SplObjectStorage<object, null> $seen
     *
     * @throws UnexpectedValueException When an incomplete object remains or the graph exceeds 64 nested levels
     */
    public static function assertComplete(mixed $value, SplObjectStorage $seen, int $depth = 0): void
    {
        AttributeSnapshotDepth::assertWithinLimit($depth);

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
}
