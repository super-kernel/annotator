<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache\Validation;

use function preg_match;

/**
 * Checks the normalized Composer package-name syntax shared by package records and their cache paths.
 *
 * @internal
 */
final class PackageNameValidator
{
    /**
     * Accepts exactly two non-empty segments of lowercase letters, digits, `_`, `.`, and `-` joined by one slash.
     */
    public static function isNormalized(string $packageName): bool
    {
        return 1 === preg_match('/\A[a-z0-9_.-]+\/[a-z0-9_.-]+\z/', $packageName);
    }
}
