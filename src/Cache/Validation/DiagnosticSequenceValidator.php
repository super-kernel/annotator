<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache\Validation;

use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\PackageAnnotations;

use function ksort;

/**
 * Validates that the diagnostics of all package records form one global sequence.
 *
 * @internal
 */
final class DiagnosticSequenceValidator
{
    /**
     * @param array<array-key, PackageAnnotations> $packages
     *
     * @throws AnnotationCacheException When diagnostic sequences are not unique and contiguous from 1
     */
    public static function validate(array $packages): void
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
}
