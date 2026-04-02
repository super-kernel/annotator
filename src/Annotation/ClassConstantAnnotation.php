<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Annotation;

use ReflectionAttribute;
use ReflectionClassConstant;
use SuperKernel\Contract\AnnotationInterface;

final readonly class ClassConstantAnnotation implements AnnotationInterface
{
	private string $name;

	private object $instance;

	private string $class;

	private string $constant;

	public function __construct(ReflectionClassConstant $reflectionClassConstant, ReflectionAttribute $reflectionAttribute)
	{
		$this->name = $reflectionAttribute->getName();
		$this->class = $reflectionClassConstant->getDeclaringClass()->getName();
		$this->constant = $reflectionClassConstant->getName();
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

	public function getConstant(): string
	{
		return $this->constant;
	}
}