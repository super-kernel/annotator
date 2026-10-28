<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Diagnostic\DiagnosticOutput;
use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\VendorDirInterface;
use Throwable;

use function array_diff_key;
use function array_keys;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function ksort;
use function mkdir;
use function preg_match;
use function realpath;
use function rename;
use function rmdir;
use function rtrim;
use function serialize;
use function sprintf;
use function strlen;
use function tempnam;
use function unlink;
use function unserialize;

final class AnnotationCache
{
    public const string INDEX_PATH = 'packages.ser';

    public const string PACKAGE_DIRECTORY = 'annotations';

    public function __construct(private readonly VendorDirInterface $vendorDir)
    {
    }

    /**
     * @param list<PackageAnnotations> $annotations
     *
     * @throws AnnotationCacheException When package records are inconsistent or cache files cannot be published
     */
    public function write(
        PackageManagerInterface $packageManager,
        array                   $annotations,
    ): void
    {
        $references = self::references($packageManager);
        $records = [];

        foreach ($annotations as $record) {
            $records[$record->packageName()] = $record;
        }

        ksort($records);

        if (array_keys($references) !== array_keys($records)) {
            throw new AnnotationCacheException('Collected annotation packages do not match Composer package metadata.');
        }

        foreach ($references as $packageName => $reference) {
            $record = $records[$packageName];

            if ($reference !== $record->reference()) {
                throw new AnnotationCacheException(sprintf(
                    'Collected annotation reference mismatch for package %s.',
                    $packageName,
                ));
            }

            if (!new SerializedPackageAnnotations($record)->isValid($packageName, $reference)) {
                throw new AnnotationCacheException('Collected annotation package data is invalid.');
            }
        }

        self::validateSequences($records);
        $previousReferences = $this->previousReferences();

        foreach ($references as $packageName => $reference) {
            $record = $records[$packageName];

            $this->writeFile(
                self::packagePath($packageName),
                serialize(new SerializedPackageAnnotations($record)),
            );
        }

        foreach (array_keys(array_diff_key($previousReferences, $references)) as $removedPackage) {
            $this->delete(self::packagePath($removedPackage));
        }

        $this->writeFile(
            self::INDEX_PATH,
            serialize(new SerializedPackageReferences($references)),
        );
    }

    /**
     * @throws Throwable When cache restoration fails, after flushing already validated diagnostics
     */
    public function read(PackageManagerInterface $packageManager): AnnotationCollectorInterface
    {
        if (!is_file($this->path(self::INDEX_PATH))) {
            throw new AnnotationCacheException('The annotation package-reference stub is unavailable.');
        }

        $index = self::decodeReferences($this->readFile(self::INDEX_PATH));
        $references = self::references($packageManager);

        if ($references !== $index->references()) {
            throw new AnnotationCacheException(
                'The annotation package-reference stub does not match current Composer metadata.',
            );
        }

        $packages = [];

        try {
            foreach ($references as $packageName => $reference) {
                $path = self::packagePath($packageName);

                if (!is_file($this->path($path))) {
                    throw new AnnotationCacheException(sprintf(
                        'The annotation cache for package %s is unavailable.',
                        $packageName,
                    ));
                }

                $packages[] = self::decodePackage($this->readFile($path), $packageName, $reference)
                    ->annotations();
            }

            self::validateSequences($packages);

            return new AnnotationCollector($packages);
        } catch (Throwable $exception) {
            try {
                DiagnosticOutput::writeToStderr(new AnnotationCollector($packages)->getDiagnostics());
            } catch (Throwable) {
                // Preserve the cache failure if emergency output also fails.
            }

            throw $exception;
        }
    }

    /**
     * @throws AnnotationCacheException When the annotation cache cannot be removed
     */
    public function invalidate(): void
    {
        $this->delete(self::INDEX_PATH);
        $this->delete(self::PACKAGE_DIRECTORY);
    }

    /** @param array<array-key, PackageAnnotations> $packages */
    private static function validateSequences(array $packages): void
    {
        $sequences = [];

        foreach ($packages as $package) {
            foreach ($package->getDiagnostics() as $diagnostic) {
                $sequence = $diagnostic['sequence'];

                if (isset($sequences[$sequence])) {
                    throw new AnnotationCacheException(
                        'Annotation diagnostic sequences must be unique and contiguous.',
                    );
                }

                $sequences[$sequence] = true;
            }
        }

        ksort($sequences);
        $expected = 1;

        foreach ($sequences as $sequence => $present) {
            if ($expected++ !== $sequence) {
                throw new AnnotationCacheException('Annotation diagnostic sequences must be unique and contiguous.');
            }
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function previousReferences(): array
    {
        if (!is_file($this->path(self::INDEX_PATH))) {
            return [];
        }

        try {
            return self::decodeReferences($this->readFile(self::INDEX_PATH))->references();
        } catch (Throwable) {
            $this->invalidate();

            return [];
        }
    }

    private function path(string $relativePath, bool $create = false): string
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

    private function readFile(string $relativePath): string
    {
        $path = $this->path($relativePath);

        if (is_link($path) || !is_file($path) || false === ($contents = @file_get_contents($path))) {
            throw new AnnotationCacheException('Unable to read the annotation cache file.');
        }

        return $contents;
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->path($relativePath, true);
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

    private function delete(string $relativePath): void
    {
        $path = $this->path($relativePath);

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

    /**
     * @return array<string, string|null>
     */
    private static function references(PackageManagerInterface $packageManager): array
    {
        $references = [];

        foreach ($packageManager->all() as $package) {
            $references[$package->name()] = $package->reference();
        }

        ksort($references);

        return $references;
    }

    private static function decodeReferences(string $serialized): SerializedPackageReferences
    {
        try {
            $decoded = @unserialize($serialized, [
                'allowed_classes' => [
                    SerializedPackageReferences::class,
                ],
            ]);

            if ($decoded instanceof SerializedPackageReferences && $decoded->isValid()) {
                return $decoded;
            }
        } catch (Throwable $exception) {
            throw new AnnotationCacheException(
                'The annotation package-reference stub cannot be decoded.',
                0,
                $exception,
            );
        }

        throw new AnnotationCacheException('The annotation package-reference stub is invalid.');
    }

    private static function decodePackage(
        string  $serialized,
        string  $packageName,
        ?string $reference,
    ): SerializedPackageAnnotations
    {
        try {
            $decoded = @unserialize($serialized, [
                'allowed_classes' => [
                    SerializedPackageAnnotations::class,
                    PackageAnnotations::class,
                    AttributeMetadata::class,
                ],
            ]);

            if ($decoded instanceof SerializedPackageAnnotations && $decoded->isValid($packageName, $reference)) {
                return $decoded;
            }
        } catch (Throwable $exception) {
            throw new AnnotationCacheException(sprintf(
                'The annotation cache for package %s cannot be decoded.',
                $packageName,
            ), 0, $exception);
        }

        throw new AnnotationCacheException(sprintf(
            'The annotation cache for package %s is invalid.',
            $packageName,
        ));
    }

    /**
     * @return non-empty-string
     */
    private static function packagePath(string $packageName): string
    {
        $segments = explode('/', $packageName);

        if (
            2 !== count($segments)
            || '.' === $segments[0]
            || '..' === $segments[0]
            || '.' === $segments[1]
            || '..' === $segments[1]
            || 1 !== preg_match('/\A[a-z0-9_.-]+\/[a-z0-9_.-]+\z/', $packageName)
        ) {
            throw new AnnotationCacheException(sprintf(
                'Invalid normalized Composer package name %s.',
                $packageName,
            ));
        }

        return self::PACKAGE_DIRECTORY . '/' . $packageName . '.ser';
    }
}
