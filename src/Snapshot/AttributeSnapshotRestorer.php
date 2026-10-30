<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Snapshot;

use SplObjectStorage;
use UnexpectedValueException;

use function in_array;
use function is_object;
use function sprintf;
use function unserialize;

/**
 * Restores a serialized Attribute snapshot through the exact object-class set derived from its own payload.
 *
 * @internal
 */
final class AttributeSnapshotRestorer
{
    /**
     * Preflights the payload with object classes disabled, restores only its derived classes, and rejects
     * incomplete objects.
     *
     * @throws UnexpectedValueException When the payload holds no object, omits or does not restore the expected
     *                                  class, contains an unnamed or incomplete object, or exceeds 64 nested levels
     */
    public static function restore(string $serialized, string $name): object
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

        $classes = AttributeSnapshotGraph::objectClasses($incomplete, new SplObjectStorage());

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

        AttributeSnapshotGraph::assertComplete($attribute, new SplObjectStorage());

        return $attribute;
    }
}
