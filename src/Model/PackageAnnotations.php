<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Model;

use SuperKernel\Contract\PackageAnnotationsInterface;
use SuperKernel\Contract\ScanDiagnosticsInterface;

final readonly class PackageAnnotations implements PackageAnnotationsInterface, ScanDiagnosticsInterface
{
    /**
     * @param non-empty-string                                                              $packageName
     * @param array<class-string, list<AttributeMetadata<object>>>                          $classAttributes
     * @param array<class-string, array<non-empty-string, list<AttributeMetadata<object>>>> $methodAttributes
     * @param array<class-string, array<non-empty-string, list<AttributeMetadata<object>>>> $propertyAttributes
     * @param list<array{
     *     sequence: int, package: string, class: string, exception_type: string, message: string
     * }> $diagnostics
     */
    public function __construct(
        private string  $packageName,
        private ?string $reference,
        private array   $classAttributes,
        private array   $methodAttributes,
        private array   $propertyAttributes,
        private array   $diagnostics = [],
    )
    {
    }

    /**
     * @return non-empty-string
     */
    public function packageName(): string
    {
        return $this->packageName;
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    /**
     * @return array<class-string, list<AttributeMetadata<object>>>
     */
    public function classAttributes(): array
    {
        return $this->classAttributes;
    }

    /**
     * @return array<class-string, array<non-empty-string, list<AttributeMetadata<object>>>>
     */
    public function methodAttributes(): array
    {
        return $this->methodAttributes;
    }

    /**
     * @return array<class-string, array<non-empty-string, list<AttributeMetadata<object>>>>
     */
    public function propertyAttributes(): array
    {
        return $this->propertyAttributes;
    }
}
