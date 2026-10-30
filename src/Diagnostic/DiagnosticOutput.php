<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Diagnostic;

use RuntimeException;

use function fwrite;
use function sprintf;
use function strlen;
use function substr;

use const PHP_EOL;
use const STDERR;

/** Emergency output shared by direct scanners and failed bootstrap operations. */
final readonly class DiagnosticOutput
{
    /** ANSI yellow warning template receiving the class, exception type, and message fields. */
    private const string WARNING_FORMAT
        = "\033[33mAnnotation scan warning: Skipping class %s because %s was thrown: %s.\033[0m";

    /**
     * @param array{sequence: int, package: string, class: string, exception_type: string, message: string} $diagnostic
     *
     * @return string The emergency ANSI warning without its terminal newline
     */
    public static function formatWarning(array $diagnostic): string
    {
        return sprintf(
            self::WARNING_FORMAT,
            $diagnostic['class'],
            $diagnostic['exception_type'],
            $diagnostic['message'],
        );
    }

    /**
     * @param list<array{
     *     sequence: int, package: string, class: string, exception_type: string, message: string
     * }> $diagnostics
     *
     * @throws RuntimeException When STDERR cannot accept a complete warning
     */
    public static function writeToStderr(array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $line = sprintf(
                self::WARNING_FORMAT,
                $diagnostic['class'],
                $diagnostic['exception_type'],
                $diagnostic['message'],
            ) . PHP_EOL;

            while ('' !== $line) {
                $written = @fwrite(STDERR, $line);

                if (false === $written || 0 === $written) {
                    throw new RuntimeException('Unable to write annotation scan diagnostics to STDERR.');
                }

                $line = substr($line, $written, strlen($line) - $written);
            }
        }
    }
}
