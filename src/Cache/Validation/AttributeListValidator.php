<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache\Validation;

use SuperKernel\Annotator\Model\AttributeMetadata;

use function array_any;
use function array_is_list;
use function array_unique;
use function is_array;
use function is_string;

/**
 * Validates a stored list of Attribute metadata and each metadata's concrete-first unique type list.
 *
 * @internal
 */
final class AttributeListValidator
{
    /**
     * Accepts a list of metadata whose types start with the concrete Attribute name and contain unique non-empty
     * strings.
     */
    public static function isValid(mixed $attributes): bool
    {
        if (!is_array($attributes) || !array_is_list($attributes)) {
            return false;
        }

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof AttributeMetadata || '' === $attribute->name()) {
                return false;
            }

            $types = $attribute->types();
            $name = $attribute->name();

            if (!array_is_list($types) || [] === $types || $name !== $types[0]) {
                return false;
            }

            if (array_any($types, fn($type) => !is_string($type) || '' === $type)) {
                return false;
            }

            if (array_unique($types) !== $types) {
                return false;
            }
        }

        return true;
    }
}
