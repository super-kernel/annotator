<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SuperKernel\Annotator\Exception\AnnotationCacheException;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function realpath;
use function rename;
use function rmdir;
use function strlen;
use function tempnam;
use function unlink;

/**
 * Reads, atomically publishes, and removes annotation cache entries through guarded cache paths.
 *
 * @internal
 */
final readonly class CacheStorage
{
    public function __construct(private CachePath $path)
    {
    }

    /**
     * @throws AnnotationCacheException When the cache path cannot be resolved
     */
    public function isFile(string $relativePath): bool
    {
        return is_file($this->path->resolve($relativePath));
    }

    /**
     * @throws AnnotationCacheException When the path cannot be resolved or the entry is a link or not a readable file
     */
    public function read(string $relativePath): string
    {
        $path = $this->path->resolve($relativePath);

        if (is_link($path) || !is_file($path) || false === ($contents = @file_get_contents($path))) {
            throw new AnnotationCacheException('Unable to read the annotation cache file.');
        }

        return $contents;
    }

    /**
     * Publishes the contents through a same-directory temporary file and an atomic rename.
     *
     * @throws AnnotationCacheException When the path cannot be resolved or the contents cannot be published
     */
    public function write(string $relativePath, string $contents): void
    {
        $path = $this->path->resolve($relativePath, true);
        $temporary = @tempnam(dirname($path), '.annotations-');

        if (false === $temporary) {
            throw new AnnotationCacheException('Unable to create an annotation cache temporary file.');
        }

        try {
            if (
                realpath(dirname($path)) !== realpath(dirname($temporary))
                || strlen($contents) !== @file_put_contents($temporary, $contents)
                || !@rename($temporary, $path)
            ) {
                throw new AnnotationCacheException('Unable to publish the annotation cache file.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Removes a file, link, or directory tree; other entries, including missing ones, are ignored.
     *
     * @throws AnnotationCacheException When the path cannot be resolved or an entry cannot be removed
     */
    public function delete(string $relativePath): void
    {
        $path = $this->path->resolve($relativePath);

        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new AnnotationCacheException('Unable to remove the annotation cache file.');
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $removed = $entry->isDir() && !$entry->isLink()
                ? @rmdir($entry->getPathname())
                : @unlink($entry->getPathname());

            if (!$removed) {
                throw new AnnotationCacheException('Unable to remove the annotation cache entry.');
            }
        }

        if (!@rmdir($path)) {
            throw new AnnotationCacheException('Unable to remove the annotation cache directory.');
        }
    }
}
