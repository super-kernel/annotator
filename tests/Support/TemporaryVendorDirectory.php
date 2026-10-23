<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SuperKernel\Contract\VendorDirInterface;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_link;
use function ksort;
use function mkdir;
use function rmdir;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final readonly class TemporaryVendorDirectory implements VendorDirInterface
{
    private string $directory;

    public function __construct()
    {
        $directory = tempnam(sys_get_temp_dir(), 'annotator-vendor-');

        if (false === $directory || !unlink($directory) || !mkdir($directory)) {
            throw new RuntimeException('Unable to create the temporary vendor directory.');
        }

        $this->directory = $directory;
    }

    public function __toString(): string
    {
        return $this->directory;
    }

    public function __destruct()
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->directory);
    }

    public function exists(string $relativePath): bool
    {
        $path = $this->directory . '/.cache/' . $relativePath;

        return file_exists($path) || is_link($path);
    }

    public function read(string $relativePath): string
    {
        $contents = file_get_contents($this->directory . '/.cache/' . $relativePath);

        return false === $contents ? throw new RuntimeException('Missing temporary cache file.') : $contents;
    }

    public function write(string $relativePath, string $contents): void
    {
        $path = $this->directory . '/.cache/' . $relativePath;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    /** @return array<string, string> */
    public function files(): array
    {
        $directory = $this->directory . '/.cache';

        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $files[substr(
                    $entry->getPathname(),
                    strlen($directory) + 1,
                )] = file_get_contents($entry->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
