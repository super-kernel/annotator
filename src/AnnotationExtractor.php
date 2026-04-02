<?php
declare(strict_types=1);

namespace SuperKernel\Annotator;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;
use SuperKernel\Annotator\Annotation\ClassAnnotation;
use SuperKernel\Annotator\Annotation\ClassConstantAnnotation;
use SuperKernel\Annotator\Annotation\MethodAnnotation;
use SuperKernel\Annotator\Annotation\PropertyAnnotation;
use SuperKernel\Contract\AnnotationInterface;
use SuperKernel\Contract\ClassLoaderInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use Throwable;
use function method_exists;
use function printf;

final readonly class AnnotationExtractor
{
	public function __construct(private ClassLoaderInterface $classLoader, private ReflectionCollectorInterface $reflectionCollector)
	{
	}

	public function getAnnotations(): array
	{
		$annotations = [];
		foreach ($this->classLoader->getClassMap() as $class => $path) {
			try {
				$reflectClass = $this->reflectionCollector->reflectClass($class);

				$this->collectAnnotations($annotations, $reflectClass);
				$this->collectAnnotations($annotations, $reflectClass->getMethods());
				$this->collectAnnotations($annotations, $reflectClass->getProperties());
			}
			catch (Throwable $throwable) {
				printf("\033[33m[WARNING]\033[0m %s in %s" . PHP_EOL, $throwable->getMessage(), $path);
			}
		}
		return $annotations;
	}

	private function collectAnnotations(array &$annotations, array|Reflector $reflector): void
	{
		$reflectors = $reflector instanceof Reflector ? [$reflector] : $reflector;

		foreach ($reflectors as $reflector) {
			if (!method_exists($reflector, 'getAttributes')) {
				return;
			}

			foreach ($reflector->getAttributes() as $reflectionAttribute) {
				$annotation = $this->getAnnotation($reflector, $reflectionAttribute);

				if (null === $annotation) {
					continue;
				}

				$annotations[] = $annotation;
			}
		}
	}

	private function getAnnotation(Reflector $reflector, ReflectionAttribute $reflectionAttribute): ?AnnotationInterface
	{
		return match (true) {
			$reflector instanceof ReflectionClass         => new ClassAnnotation($reflector, $reflectionAttribute),
			$reflector instanceof ReflectionMethod        => new MethodAnnotation($reflector, $reflectionAttribute),
			$reflector instanceof ReflectionProperty      => new PropertyAnnotation($reflector, $reflectionAttribute),
			$reflector instanceof ReflectionClassConstant => new ClassConstantAnnotation($reflector, $reflectionAttribute),
			default                                       => null,
		};
	}
}