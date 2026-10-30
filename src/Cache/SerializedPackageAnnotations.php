<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Cache;

use SuperKernel\Annotator\Cache\Validation\AttributeMapValidator;
use SuperKernel\Annotator\Cache\Validation\PackageDiagnosticsValidator;
use SuperKernel\Annotator\Model\PackageAnnotations;

final readonly class SerializedPackageAnnotations
{
    private const int SCHEMA_VERSION = 4;

    private int $schemaVersion;

    public function __construct(private PackageAnnotations $annotations)
    {
        $this->schemaVersion = self::SCHEMA_VERSION;
    }

    public function annotations(): PackageAnnotations
    {
        return $this->annotations;
    }

    public function isValid(string $packageName, ?string $reference): bool
    {
        if (
            self::SCHEMA_VERSION !== $this->schemaVersion
            || $packageName !== $this->annotations->packageName()
            || $reference !== $this->annotations->reference()
        ) {
            return false;
        }

        return AttributeMapValidator::isValidClassMap($this->annotations->classAttributes())
            && AttributeMapValidator::isValidMemberMap($this->annotations->methodAttributes())
            && AttributeMapValidator::isValidMemberMap($this->annotations->propertyAttributes())
            && PackageDiagnosticsValidator::isValid($this->annotations, $packageName);
    }
}
