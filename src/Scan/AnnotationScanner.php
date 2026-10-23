<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Scan;

use Closure;
use RuntimeException;
use SuperKernel\Annotator\Diagnostic\DiagnosticOutput;
use SuperKernel\Annotator\Exception\AnnotationScanException;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Contract\ScanDiagnosticsInterface;
use Throwable;

use function array_any;
use function file_get_contents;
use function is_array;
use function ksort;
use function sprintf;
use function token_get_all;

use const T_ATTRIBUTE;

final readonly class AnnotationScanner
{
    /**
     * @var (Closure(array{
     *     sequence: int, package: string, class: string, exception_type: string, message: string
     * }): void)
     */
    private Closure $diagnosticReporter;

    /**
     * Uses package-scoped class maps and reflects only Attribute-bearing sources.
     * Reports structured rows, using emergency STDERR output when no reporter is supplied.
     *
     * @see ScanDiagnosticsInterface::getDiagnostics()
     *
     * @param (Closure(array<string, int|string>): void)|null $diagnosticReporter Receives raw diagnostic rows
     */
    public function __construct(
        private PackageManagerInterface $packageManager,
        private ReflectorInterface      $reflector,
        ?Closure                        $diagnosticReporter = null,
    )
    {
        $this->diagnosticReporter = $diagnosticReporter ?? static function (array $diagnostic): void {
            DiagnosticOutput::writeToStderr([
                $diagnostic,
            ]);
        };
    }

    /**
     * @return list<PackageAnnotations>
     */
    public function scan(): array
    {
        $records = [];
        $sequence = 0;
        $packages = $this->packageManager->all();
        $scanner = new ClassAnnotationScanner($this->reflector);

        foreach ($packages as $package) {
            $records[$package->name()] = [
                'reference'   => $package->reference(),
                'classes'     => [],
                'methods'     => [],
                'properties'  => [],
                'diagnostics' => [],
            ];
        }

        $attributeFiles = [];

        foreach ($packages as $package) {
            $classMap = $this->packageManager->classes($package->name());

            if (null === $classMap) {
                throw new AnnotationScanException(sprintf(
                    'The Composer class map is unavailable for package %s.',
                    $package->name(),
                ));
            }

            ksort($classMap);

            foreach ($classMap as $class => $path) {
                try {
                    $containsAttributes = $attributeFiles[$path] ??= self::containsAttributes($path);

                    if (!$containsAttributes) {
                        continue;
                    }

                    $reflection = $this->reflector->reflectClass($class);
                    $collected = $scanner->collect($reflection);
                    $records[$package->name()]['classes'] += $collected['classes'];
                    $records[$package->name()]['methods'] += $collected['methods'];
                    $records[$package->name()]['properties'] += $collected['properties'];
                } catch (Throwable $exception) {
                    $diagnostic = [
                        'sequence'       => ++$sequence,
                        'package'        => $package->name(),
                        'class'          => $class,
                        'exception_type' => $exception::class,
                        'message'        => $exception->getMessage(),
                    ];
                    $records[$package->name()]['diagnostics'][] = $diagnostic;

                    ($this->diagnosticReporter)($diagnostic);

                    continue;
                }
            }
        }

        ksort($records);
        $packages = [];

        foreach ($records as $packageName => $record) {
            ksort($record['classes']);
            ksort($record['methods']);
            ksort($record['properties']);

            foreach ($record['methods'] as &$methods) {
                ksort($methods);
            }
            unset($methods);

            foreach ($record['properties'] as &$properties) {
                ksort($properties);
            }
            unset($properties);

            $packages[] = new PackageAnnotations(
                $packageName,
                $record['reference'],
                $record['classes'],
                $record['methods'],
                $record['properties'],
                $record['diagnostics'],
            );
        }

        return $packages;
    }

    private static function containsAttributes(string $path): bool
    {
        $source = @file_get_contents($path);

        if (false === $source) {
            throw new RuntimeException(sprintf('Unable to read class source file %s.', $path));
        }

        return array_any(token_get_all($source), fn($token) => is_array($token) && T_ATTRIBUTE === $token[0]);
    }

}
