<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

class AnnotatedParent
{
    #[CollectedAttribute('inherited-method')]
    public function inherited(): void
    {
    }
}
