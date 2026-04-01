<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Annotation;

use ReflectionAttribute;
use ReflectionMethod;
use SuperKernel\Annotator\Contract\AnnotationInterface;

final readonly class MethodAnnotation implements AnnotationInterface
{
	private string $name;

	private object $instance;

	private string $class;

	private string $method;

	public function __construct(ReflectionMethod $reflectionMethod, ReflectionAttribute $reflectionAttribute)
	{
		$this->name = $reflectionAttribute->getName();
		$this->class = $reflectionMethod->getDeclaringClass()->getName();
		$this->method = $reflectionMethod->getName();
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

	public function getMethod(): string
	{
		return $this->method;
	}
}