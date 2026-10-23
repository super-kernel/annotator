<?php

declare(strict_types=1);

namespace SuperKernel\Annotator;

use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AttributeMetadataInterface;
use SuperKernel\Contract\PackageAnnotationsInterface;
use SuperKernel\Contract\ScanDiagnosticsInterface;

use function strtolower;
use function usort;

final readonly class AnnotationCollector implements AnnotationCollectorInterface, ScanDiagnosticsInterface
{
    /** @var list<array{sequence: int, package: string, class: string, exception_type: string, message: string}> */
    private array $diagnostics;

    /** @var array<string, PackageAnnotationsInterface> */
    private array $packageMap;

    /** @var array<class-string, list<AttributeMetadataInterface<object>>> */
    private array $classes;

    /** @var array<class-string, array<class-string, non-empty-list<AttributeMetadataInterface<object>>>> */
    private array $classesByAttribute;

    /** @var array<class-string, array<class-string, non-empty-list<AttributeMetadataInterface<object>>>> */
    private array $classesByAssignableAttribute;

    /** @var array<class-string, array<string, list<AttributeMetadataInterface<object>>>> */
    private array $methods;

    /**
     * @var array<
     *     class-string,
     *     array<class-string, array<non-empty-string, non-empty-list<AttributeMetadataInterface<object>>>>
     * >
     */
    private array $methodsByAttribute;

    /**
     * @var array<
     *     class-string,
     *     array<class-string, array<non-empty-string, non-empty-list<AttributeMetadataInterface<object>>>>
     * >
     */
    private array $methodsByAssignableAttribute;

    /** @var array<class-string, array<string, list<AttributeMetadataInterface<object>>>> */
    private array $properties;

    /**
     * @param list<PackageAnnotationsInterface> $packages
     */
    public function __construct(array $packages)
    {
        $diagnostics = [];
        $packageMap = [];
        $classes = [];
        $methods = [];
        $properties = [];

        foreach ($packages as $package) {
            if ($package instanceof ScanDiagnosticsInterface) {
                foreach ($package->getDiagnostics() as $diagnostic) {
                    $diagnostics[] = $diagnostic;
                }
            }

            $packageMap[$package->packageName()] = $package;
            $classes += $package->classAttributes();
            $methods += $package->methodAttributes();
            $properties += $package->propertyAttributes();
        }

        $classesByAttribute = [];
        $classesByAssignableAttribute = [];

        foreach ($classes as $class => $attributes) {
            foreach ($attributes as $attribute) {
                $classesByAttribute[$attribute->name()][$class][] = $attribute;

                foreach ($attribute->types() as $type) {
                    $classesByAssignableAttribute[$type][$class][] = $attribute;
                }
            }
        }

        $methodsByAttribute = [];
        $methodsByAssignableAttribute = [];

        foreach ($methods as $class => $members) {
            foreach ($members as $method => $attributes) {
                foreach ($attributes as $attribute) {
                    $methodsByAttribute[$attribute->name()][$class][$method][] = $attribute;

                    foreach ($attribute->types() as $type) {
                        $methodsByAssignableAttribute[$type][$class][$method][] = $attribute;
                    }
                }
            }
        }

        usort($diagnostics, static fn(array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
        $this->diagnostics = $diagnostics;
        $this->packageMap = $packageMap;
        $this->classes = $classes;
        $this->classesByAttribute = $classesByAttribute;
        $this->classesByAssignableAttribute = $classesByAssignableAttribute;
        $this->methods = $methods;
        $this->methodsByAttribute = $methodsByAttribute;
        $this->methodsByAssignableAttribute = $methodsByAssignableAttribute;
        $this->properties = $properties;
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    public function getPackages(): array
    {
        return $this->packageMap;
    }

    public function getPackage(string $packageName): ?PackageAnnotationsInterface
    {
        return $this->packageMap[strtolower($packageName)] ?? null;
    }

    public function getClassesWithAttribute(string $attribute): array
    {
        return $this->classesByAttribute[$attribute] ?? [];
    }

    public function getClassesWithAttributeAssignableTo(string $attribute): array
    {
        return $this->classesByAssignableAttribute[$attribute] ?? [];
    }

    public function getClassAttributes(string $class): array
    {
        return $this->classes[$class] ?? [];
    }

    public function getMethodAttributes(string $class, string $method): array
    {
        return $this->methods[$class][$method] ?? [];
    }

    public function getMethodsWithAttribute(string $attribute): array
    {
        return $this->methodsByAttribute[$attribute] ?? [];
    }

    public function getMethodsWithAttributeAssignableTo(string $attribute): array
    {
        return $this->methodsByAssignableAttribute[$attribute] ?? [];
    }

    public function getPropertyAttributes(string $class, string $property): array
    {
        return $this->properties[$class][$property] ?? [];
    }
}
