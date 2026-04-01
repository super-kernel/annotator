<?php
declare(strict_types=1);

namespace SuperKernel\Annotator;

use SuperKernel\Annotator\Annotation\ClassAnnotation;
use SuperKernel\Annotator\Annotation\MethodAnnotation;
use SuperKernel\Annotator\Annotation\PropertyAnnotation;
use SuperKernel\Annotator\Contract\AnnotationCollectorInterface;
use SuperKernel\Annotator\Contract\AnnotationInterface;

final readonly class AnnotationCollector implements AnnotationCollectorInterface
{
	/**
	 * @var array<AnnotationInterface> $annotations
	 */
	private array $annotations;

	public function __construct(AnnotationInterface ...$annotations)
	{
	}

	public function getAnnotationsByClass(string $class): array
	{
		// TODO: Implement getAnnotationsByClass() method.
	}

	public function getAnnotationsByMethod(string $class, string $method): array
	{
		// TODO: Implement getAnnotationsByMethod() method.
	}

	public function getAnnotationsByProperty(string $class, string $property): array
	{
		// TODO: Implement getAnnotationsByProperty() method.
	}

	public function getClassesByAttribute(string $attribute): array
	{
		// TODO: Implement getClassesByAttribute() method.
	}

	public function getMethodsByAttribute(string $attribute): array
	{
		// TODO: Implement getMethodsByAttribute() method.
	}

	public function getPropertiesByAttribute(string $attribute): array
	{
		// TODO: Implement getPropertiesByAttribute() method.
	}

	public function getPropertiesByClassConstant(string $attribute): array
	{
		// TODO: Implement getPropertiesByClassConstant() method.
	}
}