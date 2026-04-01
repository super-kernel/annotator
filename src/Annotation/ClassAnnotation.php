<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Annotation;

use ReflectionAttribute;
use ReflectionClass;
use SuperKernel\Annotator\Contract\AnnotationInterface;

final readonly class ClassAnnotation implements AnnotationInterface
{
	private string $name;

	private object $instance;

	private string $class;

	public function __construct(ReflectionClass $reflectionClass, ReflectionAttribute $reflectionAttribute)
	{
		$this->name = $reflectionAttribute->getName();
		$this->class = $reflectionClass->getName();
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
}