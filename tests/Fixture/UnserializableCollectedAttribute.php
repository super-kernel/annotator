<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

use Attribute;
use Closure;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class UnserializableCollectedAttribute
{
    public Closure $callback;

    public function __construct()
    {
        $this->callback = static fn(): null => null;
    }
}
