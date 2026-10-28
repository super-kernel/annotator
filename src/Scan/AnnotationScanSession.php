<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Scan;

use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Contract\PackageManagerInterface;
use Throwable;

final class AnnotationScanSession
{
    /** @var list<PackageAnnotations>|null */
    private ?array $annotations = null;

    public function __construct(
        private readonly PackageManagerInterface $packageManager,
        private readonly AnnotationScanner       $scanner,
        private readonly AnnotationCache         $cache,
    )
    {
    }

    /** @throws Throwable When scanning fails, after invalidating the incomplete cache */
    public function scan(): void
    {
        $this->annotations = null;

        try {
            $this->annotations = $this->scanner->scan();
        } catch (Throwable $exception) {
            try {
                $this->cache->invalidate();
            } catch (Throwable) {
                // Invalidation must not replace the original scan failure.
            }

            throw $exception;
        }
    }

    /** @throws Throwable When completed scan data cannot be published, preserving the original failure */
    public function __destruct()
    {
        if (null === $this->annotations) {
            return;
        }

        $annotations = $this->annotations;
        $this->annotations = null;

        try {
            $this->cache->write($this->packageManager, $annotations);
        } catch (Throwable $exception) {
            try {
                $this->cache->invalidate();
            } catch (Throwable) {
                // Publication failure remains primary even if cleanup also fails.
            }

            throw $exception;
        }
    }
}
