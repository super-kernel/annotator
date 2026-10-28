<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Scan;

use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Diagnostic\DiagnosticOutput;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Contract\VendorDirInterface;
use Throwable;

final class AnnotationScanWorker
{
    private function __construct()
    {
    }

    /** @throws Throwable When the package scan cannot complete */
    public static function run(
        PackageManagerInterface $packageManager,
        VendorDirInterface      $vendorDir,
        ReflectorInterface      $reflector,
    ): void
    {
        $diagnostics = [];

        try {
            $session = new AnnotationScanSession(
                $packageManager,
                new AnnotationScanner(
                    $packageManager,
                    $reflector,
                    diagnosticReporter: static function (array $diagnostic) use (&$diagnostics): void {
                        $diagnostics[] = $diagnostic;
                    },
                ),
                new AnnotationCache($vendorDir),
            );
            $session->scan();
            unset($session);
        } catch (Throwable $exception) {
            try {
                DiagnosticOutput::writeToStderr($diagnostics);
            } catch (Throwable) {
                // Emergency output must not replace the original scan or publication failure.
            }

            throw $exception;
        }
    }
}
