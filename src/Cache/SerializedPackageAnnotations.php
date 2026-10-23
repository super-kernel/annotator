<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache;

use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;

use function array_all;
use function array_any;
use function array_diff_key;
use function array_is_list;
use function array_unique;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;

final readonly class SerializedPackageAnnotations
{
    private const int SCHEMA_VERSION = 4;

    private int $schemaVersion;

    public function __construct(private PackageAnnotations $annotations)
    {
        $this->schemaVersion = self::SCHEMA_VERSION;
    }

    public function annotations(): PackageAnnotations
    {
        return $this->annotations;
    }

    public function isValid(string $packageName, ?string $reference): bool
    {
        if (
            self::SCHEMA_VERSION !== $this->schemaVersion
            || $packageName !== $this->annotations->packageName()
            || $reference !== $this->annotations->reference()
        ) {
            return false;
        }

        return self::validClassAttributes($this->annotations->classAttributes())
            && self::validMemberAttributes($this->annotations->methodAttributes())
            && self::validMemberAttributes($this->annotations->propertyAttributes())
            && $this->validDiagnostics($packageName);
    }

    private function validDiagnostics(string $packageName): bool
    {
        $diagnostics = $this->annotations->getDiagnostics();

        if (!array_is_list($diagnostics) || 1 !== preg_match('/\A[a-z0-9_.-]+\/[a-z0-9_.-]+\z/', $packageName)) {
            return false;
        }

        $previous = 0;
        $classes = [];
        $keys = [
            'sequence'       => true,
            'package'        => true,
            'class'          => true,
            'exception_type' => true,
            'message'        => true,
        ];

        foreach ($diagnostics as $diagnostic) {
            if (!is_array($diagnostic) || 5 !== count($diagnostic) || [] !== array_diff_key($keys, $diagnostic)) {
                return false;
            }

            if (
                !is_int($diagnostic['sequence'])
                || $previous >= $diagnostic['sequence']
                || $packageName !== $diagnostic['package']
                || !is_string($diagnostic['class']) || '' === $diagnostic['class']
                || !is_string($diagnostic['exception_type']) || '' === $diagnostic['exception_type']
                || !is_string($diagnostic['message'])
            ) {
                return false;
            }

            $class = $diagnostic['class'];

            if (
                isset($classes[$class])
                || isset($this->annotations->classAttributes()[$class])
                || isset($this->annotations->methodAttributes()[$class])
                || isset($this->annotations->propertyAttributes()[$class])
            ) {
                return false;
            }

            $classes[$class] = true;
            $previous = $diagnostic['sequence'];
        }

        return true;
    }

    private static function validClassAttributes(array $classes): bool
    {
        return array_all(
            $classes,
            fn($attributes, $class) => is_string($class) && '' !== $class && self::validAttributes($attributes),
        );
    }

    private static function validAttributes(mixed $attributes): bool
    {
        if (!is_array($attributes) || !array_is_list($attributes)) {
            return false;
        }

        return array_all(
            $attributes,
            fn(
                $attribute,
            ) => $attribute instanceof AttributeMetadata && '' !== $attribute->name() && self::validTypes(
                $attribute->types(),
                $attribute->name(),
            ),
        );
    }

    private static function validTypes(mixed $types, string $name): bool
    {
        if (
            !is_array($types)
            || !array_is_list($types)
            || [] === $types
            || $name !== $types[0]
        ) {
            return false;
        }

        if (array_any($types, fn($type) => !is_string($type) || '' === $type)) {
            return false;
        }

        return array_unique($types) === $types;
    }

    private static function validMemberAttributes(array $classes): bool
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
                ) => !is_string($member) || '' === $member || !self::validAttributes($attributes),
            )) {
                return false;
            }
        }

        return true;
    }
}
