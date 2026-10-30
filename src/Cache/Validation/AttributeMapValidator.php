<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache\Validation;

use function array_all;
use function array_any;
use function is_array;
use function is_string;

/**
 * Validates the class-keyed Attribute maps stored in a package record.
 *
 * @internal
 */
final class AttributeMapValidator
{
    /**
     * Accepts non-empty class names mapped to valid Attribute metadata lists.
     *
     * @param array<array-key, mixed> $classes
     */
    public static function isValidClassMap(array $classes): bool
    {
        return array_all(
            $classes,
            fn(
                $attributes,
                $class,
            ) => is_string($class) && '' !== $class && AttributeListValidator::isValid($attributes),
        );
    }

    /**
     * Accepts non-empty class names mapped to non-empty member names with valid Attribute metadata lists.
     *
     * @param array<array-key, mixed> $classes
     */
    public static function isValidMemberMap(array $classes): bool
    {
        foreach ($classes as $class => $members) {
            if (!is_string($class) || '' === $class || !is_array($members)) {
                return false;
            }

            if (array_any(
                $members,
                fn(
                    $attributes,
                    $member,
                ) => !is_string($member) || '' === $member || !AttributeListValidator::isValid($attributes),
            )) {
                return false;
            }
        }

        return true;
    }
}
