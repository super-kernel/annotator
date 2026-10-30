<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Scan;

use ReflectionAttribute;
use ReflectionException;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Contract\ReflectorInterface;

use function array_unique;
use function array_values;

/**
 * Constructs reflected Attribute declarations and captures each concrete Attribute's assignable types as metadata.
 *
 * @internal
 */
final readonly class AttributeMetadataConverter
{
    public function __construct(private ReflectorInterface $reflector)
    {
    }

    /**
     * Instantiates the declarations in order; each type list starts with the concrete Attribute, followed by its
     * unique parent classes and interfaces.
     *
     * @param list<ReflectionAttribute<object>> $attributes
     *
     * @return list<AttributeMetadata<object>>
     *
     * @throws ReflectionException When an Attribute class cannot be reflected
     */
    public function convert(array $attributes): array
    {
        $metadata = [];

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $reflection = $this->reflector->reflectClass($attribute->getName());
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

            $metadata[] = new AttributeMetadata($instance, array_values(array_unique($types)));
        }

        return $metadata;
    }
}
