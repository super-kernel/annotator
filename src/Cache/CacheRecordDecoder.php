<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache;

use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use Throwable;

use function sprintf;
use function unserialize;

/**
 * Restores serialized cache records through their narrow class allow-lists and validates them.
 *
 * @internal
 */
final class CacheRecordDecoder
{
    /**
     * @throws AnnotationCacheException When the reference stub cannot be decoded or is invalid
     */
    public static function decodeReferences(string $serialized): SerializedPackageReferences
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

    /**
     * @throws AnnotationCacheException When the record cannot be decoded or is invalid for the package and reference
     */
    public static function decodePackage(
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
}
