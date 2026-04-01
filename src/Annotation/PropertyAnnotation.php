<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Annotation;

use ReflectionAttribute;
use ReflectionProperty;
use SuperKernel\Annotator\Contract\AnnotationInterface;

final readonly class PropertyAnnotation implements AnnotationInterface
{
	private string $name;

	private object $instance;

	private string $class;

	private string $property;

	public function __construct(ReflectionProperty $reflectionProperty, ReflectionAttribute $reflectionAttribute)
	{
		$this->name = $reflectionAttribute->getName();
		$this->class = $reflectionProperty->getDeclaringClass()->getName();
		$this->property = $reflectionProperty->getName();
		$this->instance = $reflectionAttribute->newInstance();
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getInstance(): object
	{
		return $this->instance;
	}

	public function getClass(): string
	{
		return $this->class;
	}

	public function getProperty(): string
	{
		return $this->property;
	}
}