<?php
declare(strict_types=1);

namespace SuperKernel\Annotator;

use SuperKernel\Annotator\Annotation\ClassAnnotation;
use SuperKernel\Annotator\Annotation\ClassConstantAnnotation;
use SuperKernel\Annotator\Annotation\MethodAnnotation;
use SuperKernel\Annotator\Annotation\PropertyAnnotation;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AnnotationInterface;

final readonly class AnnotationCollector implements AnnotationCollectorInterface
{
	/**
	 * @var array<AnnotationInterface> $annotations
	 */
	private array $annotations;

	public function __construct(AnnotationInterface ...$annotations)
	{
		$storage = [
			AnnotationInterface::TARGET_CLASS          => [],
			AnnotationInterface::TARGET_METHOD         => [],
			AnnotationInterface::TARGET_PROPERTY       => [],
			AnnotationInterface::TARGET_CLASS_CONSTANT => [],
			AnnotationInterface::TARGET_ALL            => [],
		];

		foreach ($annotations as $annotation) {
			$class = $annotation->getClass();
			$name = $annotation->getName();

			$storage[AnnotationInterface::TARGET_ALL][$name][] = $annotation;

			if ($annotation instanceof ClassAnnotation) {
				$storage[AnnotationInterface::TARGET_CLASS][$class][] = $annotation;
			} elseif ($annotation instanceof MethodAnnotation) {
				$storage[AnnotationInterface::TARGET_METHOD][$class][$annotation->getMethod()][] = $annotation;
			} elseif ($annotation instanceof PropertyAnnotation) {
				$storage[AnnotationInterface::TARGET_PROPERTY][$class][$annotation->getProperty()][] = $annotation;
			} elseif ($annotation instanceof ClassConstantAnnotation) {
				$storage[AnnotationInterface::TARGET_CLASS_CONSTANT][$class][$annotation->getConstant()][] = $annotation;
			}
		}

		$this->annotations = $storage;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getAnnotationsByClass(string $class): array
	{
		$annotations = [];
		foreach ($this->annotations as $annotation) {
			if (!($annotation instanceof ClassAnnotation)) {
				continue;
			}
			if ($annotation->getClass() === $class) {
				$annotations[] = $annotation;
			}
		}
		return $annotations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getAnnotationsByMethod(string $class, string $method): array
	{
		$annotations = [];
		foreach ($this->annotations as $annotation) {
			if (!($annotation instanceof MethodAnnotation)) {
				continue;
			}
			if ($annotation->getClass() === $class && $annotation->getMethod() === $method) {
				$annotations[] = $annotation;
			}
		}
		return $annotations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getAnnotationsByProperty(string $class, string $property): array
	{
		$annotations = [];
		foreach ($this->annotations as $annotation) {
			if (!($annotation instanceof PropertyAnnotation)) {
				continue;
			}
			if ($annotation->getClass() === $class && $annotation->getProperty() === $property) {
				$annotations[] = $annotation;
			}
		}
		return $annotations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getAnnotationsByClassConstant(string $class, string $constant): array
	{
		$annotations = [];
		foreach ($this->annotations as $annotation) {
			if (!($annotation instanceof ClassConstantAnnotation)) {
				continue;
			}
			if ($annotation->getClass() === $class && $annotation->getConstant() === $constant) {
				$annotations[] = $annotation;
			}
		}
		return $annotations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getClassesByAttribute(string $attribute): array
	{
		$annotations = [];
		foreach ($this->annotations as $annotation) {
			if (!($annotation instanceof ClassAnnotation)) {
				continue;
			}
			if ($annotation->getName() === $attribute) {
				$annotations[] = $annotation;
			}
		}
		return $annotations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getMethodsByAttribute(string $attribute): array
	{
		$annotations = [];
		foreach ($this->annotations as $annotation) {
			if (!($annotation instanceof MethodAnnotation)) {
				continue;
			}
			if ($annotation->getName() === $attribute) {
				$annotations[] = $annotation;
			}
		}
		return $annotations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getPropertiesByAttribute(string $attribute): array
	{
		$annotations = [];
		foreach ($this->annotations as $annotation) {
			if (!($annotation instanceof PropertyAnnotation)) {
				continue;
			}
			if ($annotation->getName() === $attribute) {
				$annotations[] = $annotation;
			}
		}
		return $annotations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getClassConstantsByAttribute(string $attribute): array
	{
		$annotations = [];
		foreach ($this->annotations as $annotation) {
			if (!($annotation instanceof ClassConstantAnnotation)) {
				continue;
			}
			if ($annotation->getName() === $attribute) {
				$annotations[] = $annotation;
			}
		}
		return $annotations;
	}
}