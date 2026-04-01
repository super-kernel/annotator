<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Contract;

/**
 * Provides a unified interface for querying collected PHP attributes.
 *
 * This collector indexes attributes declared on classes, methods, properties, and constants, exposing them through a
 * consistent lookup API.
 * * Note: Since attributes can be repeatable, all query methods return an array of {@see AnnotationInterface}
 * instances.
 */
interface AnnotationCollectorInterface
{
	/**
	 * Returns attributes declared on the specified class.
	 *
	 * @param string $class
	 *
	 * @return array<AnnotationInterface>
	 */
	public function getAnnotationsByClass(string $class): array;

	/**
	 * Returns attributes declared on the specified class method.
	 *
	 * @param string $class
	 * @param string $method
	 *
	 * @return array<AnnotationInterface>
	 */
	public function getAnnotationsByMethod(string $class, string $method): array;

	/**
	 * Returns attributes declared on the specified class property.
	 *
	 * @param string $class
	 * @param string $property
	 *
	 * @return array<AnnotationInterface>
	 */
	public function getAnnotationsByProperty(string $class, string $property): array;

	/**
	 * Returns class attributes matching the specified attribute class.
	 *
	 * @param string $attribute
	 *
	 * @return array<AnnotationInterface>
	 */
	public function getClassesByAttribute(string $attribute): array;

	/**
	 * Returns method attributes matching the specified attribute class.
	 *
	 * @param string $attribute
	 *
	 * @return array<AnnotationInterface>
	 */
	public function getMethodsByAttribute(string $attribute): array;

	/**
	 * Returns property attributes matching the specified attribute class.
	 *
	 * @param string $attribute
	 *
	 * @return array<AnnotationInterface>
	 */
	public function getPropertiesByAttribute(string $attribute): array;

	/**
	 * Returns class constant attributes matching the specified attribute class.
	 *
	 * @param string $attribute
	 *
	 * @return array<AnnotationInterface>
	 */
	public function getPropertiesByClassConstant(string $attribute): array;
}
