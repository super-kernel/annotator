<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache;

use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Cache\Storage\CachePath;
use SuperKernel\Annotator\Cache\Storage\CacheStorage;
use SuperKernel\Annotator\Cache\Storage\PackageRecordPath;
use SuperKernel\Annotator\Cache\Validation\DiagnosticSequenceValidator;
use SuperKernel\Annotator\Constants\CacheLayout;
use SuperKernel\Annotator\Diagnostic\DiagnosticOutput;
use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\VendorDirInterface;
use Throwable;

use function array_diff_key;
use function array_keys;
use function ksort;
use function serialize;
use function sprintf;

final readonly class AnnotationCache
{
    private CacheStorage $storage;

    public function __construct(VendorDirInterface $vendorDir)
    {
        $this->storage = new CacheStorage(new CachePath($vendorDir));
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
        $references = PackageReferenceSnapshot::capture($packageManager);
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

        DiagnosticSequenceValidator::validate($records);
        $previousReferences = [];

        if ($this->storage->isFile(CacheLayout::IndexPath->value)) {
            try {
                $previousReferences = CacheRecordDecoder::decodeReferences(
                    $this->storage->read(CacheLayout::IndexPath->value),
                )->references();
            } catch (Throwable) {
                $this->storage->delete(CacheLayout::IndexPath->value);
                $this->storage->delete(CacheLayout::PackageDirectory->value);
            }
        }

        foreach ($references as $packageName => $reference) {
            $record = $records[$packageName];

            $this->storage->write(
                PackageRecordPath::fromPackageName($packageName),
                serialize(new SerializedPackageAnnotations($record)),
            );
        }

        foreach (array_keys(array_diff_key($previousReferences, $references)) as $removedPackage) {
            $this->storage->delete(PackageRecordPath::fromPackageName($removedPackage));
        }

        $this->storage->write(
            CacheLayout::IndexPath->value,
            serialize(new SerializedPackageReferences($references)),
        );
    }

    /**
     * @throws Throwable When cache restoration fails, after flushing already validated diagnostics
     */
    public function read(PackageManagerInterface $packageManager): AnnotationCollectorInterface
    {
        if (!$this->storage->isFile(CacheLayout::IndexPath->value)) {
            throw new AnnotationCacheException('The annotation package-reference stub is unavailable.');
        }

        $index = CacheRecordDecoder::decodeReferences($this->storage->read(CacheLayout::IndexPath->value));
        $references = PackageReferenceSnapshot::capture($packageManager);

        if ($references !== $index->references()) {
            throw new AnnotationCacheException(
                'The annotation package-reference stub does not match current Composer metadata.',
            );
        }

        $packages = [];

        try {
            foreach ($references as $packageName => $reference) {
                $path = PackageRecordPath::fromPackageName($packageName);

                if (!$this->storage->isFile($path)) {
                    throw new AnnotationCacheException(sprintf(
                        'The annotation cache for package %s is unavailable.',
                        $packageName,
                    ));
                }

                $packages[] = CacheRecordDecoder::decodePackage($this->storage->read($path), $packageName, $reference)
                    ->annotations();
            }

            DiagnosticSequenceValidator::validate($packages);

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
        $this->storage->delete(CacheLayout::IndexPath->value);
        $this->storage->delete(CacheLayout::PackageDirectory->value);
    }
}
