<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

use Attribute;
use Stringable;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class DerivedMethodAttribute extends CollectedAttribute implements Stringable
{
    public function __toString(): string
    {
        return $this->value;
    }
}
