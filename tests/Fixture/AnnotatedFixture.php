<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Fixture;

#[CollectedAttribute('class', FixtureMode::Ready)]
#[CollectedAttribute('class-repeat')]
#[DerivedCollectedAttribute('derived-class')]
final class AnnotatedFixture extends AnnotatedParent
{
    /**
     * Scanned through reflection to verify property attributes.
     *
     * @noinspection PhpUnusedPrivateFieldInspection
     */
    #[CollectedAttribute('property')]
    private string $value = '';

    #[CollectedAttribute('method')]
    public function handle(): void
    {
    }
}
