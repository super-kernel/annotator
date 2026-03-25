<?php
declare(strict_types=1);

namespace SuperKernel\Annotator;

use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AnnotationInterface;

final readonly class AnnotationCollector implements AnnotationCollectorInterface
{
	/**
	 * @var array<string, AnnotationInterface> $attributes
	 */
	private array $attributes;

	public function __construct(AnnotationInterface ...$attributeMetadataCollection)
	{
		$attributes = [];
		foreach ($attributeMetadataCollection as $attributeMetadata) {
			$class = $attributeMetadata->getClass();
			if ($attributeMetadata->compatible(AnnotationInterface::TARGET_CLASS)) {
				$attributes[$class][AnnotationInterface::TARGET_CLASS][] = $attributeMetadata;
			} elseif ($attributeMetadata->compatible(AnnotationInterface::TARGET_METHOD)) {
				$attributes[$class][AnnotationInterface::TARGET_METHOD][$attributeMetadata->getMethod()][] = $attributeMetadata;
			} elseif ($attributeMetadata->compatible(AnnotationInterface::TARGET_PROPERTY)) {
				$attributes[$class][AnnotationInterface::TARGET_PROPERTY][$attributeMetadata->getProperty()][] = $attributeMetadata;
			}
		}

		$this->attributes = $attributes;
	}

	public function getClassAttributes(string $class): array
	{
		return $this->attributes[$class][AnnotationInterface::TARGET_CLASS] ?? [];
	}

	public function getMethodAttributes(string $class, string $method): array
	{
		return $this->attributes[$class][AnnotationInterface::TARGET_METHOD][$method] ?? [];
	}

	public function getPropertyAttributes(string $class, string $property): array
	{
		return $this->attributes[$class][AnnotationInterface::TARGET_PROPERTY][$property] ?? [];
	}

	public function getClassesByAttribute(string $attribute): array
	{
		$attributes = [];

		foreach ($this->attributes as $targets) {
			if (!isset($targets[AnnotationInterface::TARGET_CLASS])) {
				continue;
			}

			/* @var AnnotationInterface $classAttribute */
			foreach ($targets[AnnotationInterface::TARGET_CLASS] ?? [] as $classAttribute) {
				if ($classAttribute->getAttribute() === $attribute) {
					$attributes[] = $classAttribute;
				}
			}
		}

		return $attributes;
	}

	public function getMethodsByAttribute(string $attribute): array
	{
		$attributes = [];

		foreach ($this->attributes as $targets) {
			if (!isset($targets[AnnotationInterface::TARGET_METHOD])) {
				continue;
			}
			foreach ($targets[AnnotationInterface::TARGET_METHOD] ?? [] as $methods) {
				foreach ($methods as $method) {
					if ($method->getAttribute() === $attribute) {
						$attributes[] = $method;
					}
				}
			}
		}

		return $attributes;
	}

	public function getPropertiesByAttribute(string $attribute): array
	{
		$attributes = [];

		foreach ($this->attributes as $targets) {
			if (!isset($targets[AnnotationInterface::TARGET_PROPERTY])) {
				continue;
			}

			foreach ($targets[AnnotationInterface::TARGET_PROPERTY] ?? [] as $properties) {
				foreach ($properties as $property) {
					if ($property->getAttribute() === $attribute) {
						$attributes[] = $property;
					}
				}
			}
		}

		return $attributes;
	}
}