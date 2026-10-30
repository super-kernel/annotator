<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache\Storage;

use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Contract\VendorDirInterface;

use function dirname;
use function explode;
use function file_exists;
use function is_dir;
use function is_link;
use function mkdir;
use function rtrim;

/**
 * Resolves cache-relative paths below the vendor `.cache` directory and guards every parent directory.
 *
 * @internal
 */
final readonly class CachePath
{
    public function __construct(private VendorDirInterface $vendorDir)
    {
    }

    /**
     * @param bool $create Whether missing parent directories are created
     *
     * @throws AnnotationCacheException When the vendor directory is empty, a parent is a link or not a directory,
     *                                  or a missing parent cannot be created
     */
    public function resolve(string $relativePath, bool $create = false): string
    {
        $vendor = rtrim((string) $this->vendorDir, '/');

        if ('' === $vendor) {
            throw new AnnotationCacheException('The annotation cache requires a non-empty vendor directory.');
        }

        $directory = $vendor;

        foreach (explode('/', '.cache/' . dirname($relativePath)) as $segment) {
            if ('.' === $segment) {
                continue;
            }

            $directory .= '/' . $segment;

            if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
                throw new AnnotationCacheException('The annotation cache parent is not a regular directory.');
            }

            if ($create && !is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
                throw new AnnotationCacheException('Unable to create the annotation cache directory.');
            }
        }

        return $vendor . '/.cache/' . $relativePath;
    }
}
