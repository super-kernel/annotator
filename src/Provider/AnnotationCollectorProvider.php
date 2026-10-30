<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Provider;

use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Exception\AnnotationScanException;
use SuperKernel\Annotator\Scan\AnnotationScanWorker;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Contract\VendorDirInterface;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use Throwable;

#[FactoryProvider(AnnotationCollectorInterface::class)]
final readonly class AnnotationCollectorProvider
{
    /**
     * @throws AnnotationScanException When the injected scan processor does not support the current runtime
     * @throws Throwable               When scan execution fails or the published package records cannot be restored
     */
    public function __invoke(
        PackageManagerInterface $packageManager,
        ScanProcessorInterface  $scanProcessor,
        ReflectorInterface      $reflector,
        VendorDirInterface      $vendorDir,
    ): AnnotationCollectorInterface
    {
        if (!$scanProcessor->support()) {
            throw new AnnotationScanException('The injected scan processor does not support the current runtime.');
        }

        $scanProcessor->execute(
            static function () use ($packageManager, $reflector, $vendorDir): void {
                AnnotationScanWorker::run(
                    $packageManager,
                    $vendorDir,
                    $reflector,
                );
            },
        );

        return new AnnotationCache($vendorDir)->read($packageManager);
    }
}
