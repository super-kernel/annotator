<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
readonly class CollectedAttribute
{
    public function __construct(public string $value, public ?FixtureMode $mode = null)
    {
    }
}
