<?php
declare(strict_types=1);

namespace SuperKernel\Annotator;

use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;
use SuperKernel\Contract\AnnotationInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use Throwable;
use function get_object_vars;
use function method_exists;
use function property_exists;

final readonly class Annotation implements AnnotationInterface
{
	private string $attribute;

	private string $class;

	private string $method;

	private string $function;

	private string $property;

	private string $classConstant;

	private int $parameterIndex;

	private int $target;

	private array $arguments;

	public function __construct(Reflector $reflector, ReflectionAttribute $reflectionAttribute)
	{
		if ($reflector instanceof ReflectionClass
		    || $reflector instanceof ReflectionMethod
		    || $reflector instanceof ReflectionProperty
		    || $reflector instanceof ReflectionClassConstant
		) {
			$this->class = method_exists($reflector, 'getDeclaringClass')
				? $reflector->getDeclaringClass()->getName()
				: $reflector->getName();
		}

		if ($reflector instanceof ReflectionMethod) {
			$this->method = $reflector->getName();
		}
		if ($reflector instanceof ReflectionProperty) {
			$this->property = $reflector->getName();
		}
		if ($reflector instanceof ReflectionFunction) {
			$this->function = $reflector->getName();
		}
		if ($reflector instanceof ReflectionClassConstant) {
			$this->classConstant = $reflector->getName();
		}
		if ($reflector instanceof ReflectionParameter) {
			$this->function = $reflector->getDeclaringFunction()->getName();
			$this->parameterIndex = $reflector->getPosition();
		}

		$this->target = $reflectionAttribute->getTarget();
		$this->attribute = $reflectionAttribute->getName();
		$this->arguments = $reflectionAttribute->getArguments();
	}

	public function getAttribute(): string
	{
		return $this->attribute;
	}

	public function getInstance(): object
	{
		$attributeClass = $this->attribute;

		return new $attributeClass(...$this->arguments);
	}

	public function getTarget(): int
	{
		return $this->target;
	}

	public function compatible(int $type): bool
	{
		return ($this->target & $type) !== 0;
	}

	public function getReflector(ReflectionCollectorInterface $reflectionCollector): Reflector
	{
		try {
			return match (true) {
				!isset($this->class)
				&& isset($this->method)
				&& isset($this->property)
				&& isset($this->classConstant)
				                             => $reflectionCollector->reflectClass($this->class),
				isset($this->method)         => $reflectionCollector->reflectMethod($this->class, $this->method),
				isset($this->property)       => $reflectionCollector->reflectProperty($this->class, $this->property),
				isset($this->classConstant)  => $reflectionCollector->reflectClassConstant($this->class, $this->classConstant),
				isset($this->parameterIndex) => $reflectionCollector->reflectFunction($this->function)->getParameters()[$this->parameterIndex],
				isset($this->function)       => $reflectionCollector->reflectFunction($this->function),
				default                      => throw new LogicException('Unable to resolve reflector.'),
			};
		}
		catch (Throwable $throwable) {
			throw new LogicException($throwable->getMessage());
		}
	}

	public function getClass(): string
	{
		if (isset($this->class)) {
			return $this->class;
		}

		throw new LogicException('Class cannot be found.');
	}

	public function getMethod(): string
	{
		if (isset($this->method)) {
			return $this->method;
		}

		throw new LogicException('Method cannot be found.');
	}

	public function getFunction(): string
	{
		if (isset($this->function)) {
			return $this->function;
		}

		throw new LogicException('Function cannot be found.');
	}

	public function getProperty(): string
	{
		if (isset($this->property)) {
			return $this->property;
		}
		throw new LogicException('Property cannot be found.');
	}

	public function getClassConstant(): string
	{
		if (isset($this->classConstant)) {
			return $this->classConstant;
		}
		throw new LogicException('Class constant cannot be found.');
	}

	public function getParameterIndex(): int
	{
		if (isset($this->parameterIndex)) {
			return $this->parameterIndex;
		}
		throw new LogicException('Parameter index cannot be found.');
	}

	public function __serialize(): array
	{
		return get_object_vars($this);
	}

	public function __unserialize(array $data): void
	{
		foreach ($data as $key => $value) {
			if (property_exists($this, $key)) {
				$this->{$key} = $value;
			}
		}
	}
}