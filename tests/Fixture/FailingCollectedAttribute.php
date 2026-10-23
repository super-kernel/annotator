<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

use Attribute;
use RuntimeException;

#[Attribute(Attribute::TARGET_CLASS)]
final class FailingCollectedAttribute
{
    public function __construct()
    {
        throw new RuntimeException('attribute construction failure');
    }
}
