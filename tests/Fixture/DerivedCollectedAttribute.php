<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DerivedCollectedAttribute extends CollectedAttribute
{
}
