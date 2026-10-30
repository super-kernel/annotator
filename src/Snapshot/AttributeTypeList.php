<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Snapshot;

use InvalidArgumentException;

use function array_unique;
use function array_values;
use function get_debug_type;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Normalizes captured Attribute types into a concrete-first unique list the instance is assignable to.
 *
 * @internal
 */
final class AttributeTypeList
{
    /**
     * @param list<class-string> $types Captured parent classes and implemented interfaces
     *
     * @return non-empty-list<class-string>
     *
     * @throws InvalidArgumentException When a type is not a non-empty string the Attribute is assignable to
     */
    public static function normalize(object $attribute, array $types): array
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
}
