<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache;

use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\VendorDirInterface;
use Throwable;

use function array_values;
use function ksort;
use function sprintf;

use const SORT_STRING;

/**
 * Publishes complete annotation records for a build generator that collected them outside this package.
 *
 * Records may arrive in any order and with unsorted maps; the published files are the same for the same content.
 * A failed publication removes every annotation record, so restoration fails instead of using partial data.
 */
final readonly class AnnotationPublisher
{
    private AnnotationCache $cache;

    public function __construct(VendorDirInterface $vendorDir)
    {
        $this->cache = new AnnotationCache($vendorDir);
    }

    /**
     * @param PackageManagerInterface  $packageManager Packages whose names and references the records must match
     * @param list<PackageAnnotations> $annotations    Exactly one record per package of the manager; diagnostic
     *                                                 sequences run from 1 through N across the whole build
     *
     * @throws AnnotationCacheException When a record is not a PackageAnnotations model, a package has more than one
     *                                  record, or the records are inconsistent with the package manager
     * @throws Throwable                When publication fails, after the annotation records were removed
     */
    public function publish(PackageManagerInterface $packageManager, array $annotations): void
    {
        $sort = static function (array $members): array {
            ksort($members, SORT_STRING);

            foreach ($members as &$attributes) {
                ksort($attributes, SORT_STRING);
            }

            return $members;
        };

        try {
            $records = [];

            foreach ($annotations as $record) {
                if (!$record instanceof PackageAnnotations) {
                    throw new AnnotationCacheException('Every published annotation record must be PackageAnnotations.');
                }

                if (isset($records[$record->packageName()])) {
                    throw new AnnotationCacheException(sprintf(
                        'Package %s has more than one published annotation record.',
                        $record->packageName(),
                    ));
                }

                $classes = $record->classAttributes();
                ksort($classes, SORT_STRING);
                $records[$record->packageName()] = new PackageAnnotations(
                    $record->packageName(),
                    $record->reference(),
                    $classes,
                    $sort($record->methodAttributes()),
                    $sort($record->propertyAttributes()),
                    $record->getDiagnostics(),
                );
            }

            $this->cache->write($packageManager, array_values($records));
        } catch (Throwable $exception) {
            try {
                $this->cache->invalidate();
            } catch (Throwable) {
                // The publication failure remains primary even if cleanup also fails.
            }

            throw $exception;
        }
    }
}
