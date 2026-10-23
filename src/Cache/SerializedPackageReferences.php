<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache;

use function is_string;

final readonly class SerializedPackageReferences
{
    /**
     * @param array<string, string|null> $references
     */
    public function __construct(private array $references)
    {
    }

    /**
     * @return array<string, string|null>
     */
    public function references(): array
    {
        return $this->references;
    }

    public function isValid(): bool
    {
        foreach ($this->references as $packageName => $reference) {
            if (!is_string($packageName) || '' === $packageName) {
                return false;
            }

            if (null !== $reference && !is_string($reference)) {
                return false;
            }
        }

        return true;
    }
}
