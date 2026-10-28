<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

#[DerivedCollectedAttribute('first')]
#[DerivedCollectedAttribute('second')]
final class RepeatedAnnotatedFixture
{
}
