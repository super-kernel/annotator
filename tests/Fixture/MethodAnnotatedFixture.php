<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

final class MethodAnnotatedFixture extends AnnotatedParent
{
    #[CollectedAttribute('first')]
    #[DerivedMethodAttribute('derived')]
    #[CollectedAttribute('second')]
    public function handle(): void
    {
    }
}
