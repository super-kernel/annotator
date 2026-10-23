<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Exception;

use RuntimeException;

final class AnnotationScanException extends RuntimeException implements AnnotationCollectorExceptionInterface
{
}
