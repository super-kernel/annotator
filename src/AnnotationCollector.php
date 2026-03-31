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

	public function __construct(AnnotationInterface ...$annotations)
	{
		$attributes = [];
		foreach ($annotations as $annotation) {
			if (
				!$annotation->compatible(
					AnnotationInterface::TARGET_CLASS
					| AnnotationInterface::TARGET_METHOD
					| AnnotationInterface::TARGET_PROPERTY,
				)
			) {
				continue;
			}

			$class = $annotation->getClass();
			if ($annotation->compatible(AnnotationInterface::TARGET_CLASS)) {
				$attributes[$class][AnnotationInterface::TARGET_CLASS][] = $annotation;
			} elseif ($annotation->compatible(AnnotationInterface::TARGET_METHOD)) {
				$attributes[$class][AnnotationInterface::TARGET_METHOD][$annotation->getMethod()][] = $annotation;
			} elseif ($annotation->compatible(AnnotationInterface::TARGET_PROPERTY)) {
				$attributes[$class][AnnotationInterface::TARGET_PROPERTY][$annotation->getProperty()][] = $annotation;
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