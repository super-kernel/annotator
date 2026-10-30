<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache\Storage;

use SuperKernel\Annotator\Cache\Validation\PackageNameValidator;
use SuperKernel\Annotator\Constants\CacheLayout;
use SuperKernel\Annotator\Exception\AnnotationCacheException;

use function count;
use function explode;
use function sprintf;

/**
 * Maps normalized Composer package names to cache-relative package record paths.
 *
 * @internal
 */
final class PackageRecordPath
{
    /**
     * @return non-empty-string
     *
     * @throws AnnotationCacheException When the name is not a normalized Composer package name
     */
    public static function fromPackageName(string $packageName): string
    {
        $segments = explode('/', $packageName);

        if (
            2 !== count($segments)
            || '.' === $segments[0]
            || '..' === $segments[0]
            || '.' === $segments[1]
            || '..' === $segments[1]
            || !PackageNameValidator::isNormalized($packageName)
        ) {
            throw new AnnotationCacheException(sprintf(
                'Invalid normalized Composer package name %s.',
                $packageName,
            ));
        }

        return CacheLayout::PackageDirectory->value . '/' . $packageName . '.ser';
    }
}
