<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache;

use SuperKernel\Contract\PackageManagerInterface;

use function ksort;

/**
 * Captures the current package-name-to-reference map from injected Composer metadata.
 *
 * @internal
 */
final class PackageReferenceSnapshot
{
    /**
     * @return array<string, string|null> References keyed by package name in ascending order
     */
    public static function capture(PackageManagerInterface $packageManager): array
    {
        $references = [];

        foreach ($packageManager->all() as $package) {
            $references[$package->name()] = $package->reference();
        }

        ksort($references);

        return $references;
    }
}
