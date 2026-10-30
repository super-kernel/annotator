<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache\Validation;

use SuperKernel\Annotator\Model\PackageAnnotations;

use function array_diff_key;
use function array_is_list;
use function count;
use function is_array;
use function is_int;
use function is_string;

/**
 * Validates the skipped-class diagnostic rows stored in one package record.
 *
 * @internal
 */
final class PackageDiagnosticsValidator
{
    /**
     * Requires a list of exact scalar rows owned by the normalized package name, with increasing positive
     * sequences, no repeated class, and no metadata for a skipped class.
     */
    public static function isValid(PackageAnnotations $annotations, string $packageName): bool
    {
        $diagnostics = $annotations->getDiagnostics();

        if (!array_is_list($diagnostics) || !PackageNameValidator::isNormalized($packageName)) {
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
                || isset($annotations->classAttributes()[$class])
                || isset($annotations->methodAttributes()[$class])
                || isset($annotations->propertyAttributes()[$class])
            ) {
                return false;
            }

            $classes[$class] = true;
            $previous = $diagnostic['sequence'];
        }

        return true;
    }
}
