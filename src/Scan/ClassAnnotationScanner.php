<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Scan;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Contract\ReflectorInterface;

use function array_unique;
use function array_values;
use function strcmp;
use function usort;

/**
 * Extracts native class and declared-member attributes for package scans and finite bootstrap graphs.
 *
 * Shared with DI bootstrap; callers select the classes to scan and own failure handling.
 */
final readonly class ClassAnnotationScanner
{
    public function __construct(private ReflectorInterface $reflector)
    {
    }

    /**
     * @return array{
     *     classes: array<class-string, list<AttributeMetadata<object>>>,
     *     methods: array<class-string, array<non-empty-string, list<AttributeMetadata<object>>>>,
     *     properties: array<class-string, array<non-empty-string, list<AttributeMetadata<object>>>>
     * }
     *
     * @throws ReflectionException
     */
    public function collect(ReflectionClass $reflection): array
    {
        $record = [
            'classes'    => [],
            'methods'    => [],
            'properties' => [],
        ];
        $class = $reflection->getName();
        $classAttributes = $this->attributes($reflection->getAttributes());

        if ([] !== $classAttributes) {
            $record['classes'][$class] = $classAttributes;
        }

        $methods = $reflection->getMethods();
        usort(
            $methods,
            static fn(ReflectionMethod $left, ReflectionMethod $right): int => strcmp(
                $left->getName(),
                $right->getName(),
            ),
        );

        foreach ($methods as $method) {
            if ($class !== $method->getDeclaringClass()->getName()) {
                continue;
            }

            $attributes = $this->attributes($method->getAttributes());

            if ([] !== $attributes) {
                $record['methods'][$class][$method->getName()] = $attributes;
            }
        }

        $properties = $reflection->getProperties();
        usort(
            $properties,
            static fn(ReflectionProperty $left, ReflectionProperty $right): int => strcmp(
                $left->getName(),
                $right->getName(),
            ),
        );

        foreach ($properties as $property) {
            if ($class !== $property->getDeclaringClass()->getName()) {
                continue;
            }

            $attributes = $this->attributes($property->getAttributes());

            if ([] !== $attributes) {
                $record['properties'][$class][$property->getName()] = $attributes;
            }
        }

        return $record;
    }

    /**
     * @param list<ReflectionAttribute<object>> $attributes
     *
     * @return list<AttributeMetadata<object>>
     *
     * @throws ReflectionException
     */
    private function attributes(array $attributes): array
    {
        $metadata = [];

        foreach ($attributes as $attribute) {
            $metadata[] = new AttributeMetadata(
                $attribute->newInstance(),
                $this->attributeTypes($attribute->getName()),
            );
        }

        return $metadata;
    }

    /**
     * @param class-string $attribute
     *
     * @return non-empty-list<class-string>
     *
     * @throws ReflectionException
     */
    private function attributeTypes(string $attribute): array
    {
        $reflection = $this->reflector->reflectClass($attribute);
        $types = [
            $reflection->getName(),
        ];
        $parent = $reflection->getParentClass();

        while (false !== $parent) {
            $types[] = $parent->getName();
            $parent = $parent->getParentClass();
        }

        foreach ($reflection->getInterfaceNames() as $interface) {
            $types[] = $interface;
        }

        return array_values(array_unique($types));
    }
}
