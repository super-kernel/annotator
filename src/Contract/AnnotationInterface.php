<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Contract;

use Attribute;

interface AnnotationInterface
{
	/**
	 * Attribute declared on a class.
	 */
	public const int TARGET_CLASS = Attribute::TARGET_CLASS;

	/**
	 * Attribute declared on a class method.
	 */
	public const int TARGET_METHOD = Attribute::TARGET_METHOD;

	/**
	 * Attribute declared on a class property.
	 */
	public const int TARGET_PROPERTY = Attribute::TARGET_PROPERTY;

	/**
	 * Attribute declared on a class constant.
	 */
	public const int TARGET_CLASS_CONSTANT = Attribute::TARGET_CLASS_CONSTANT;

	public function getName(): string;

	public function getInstance(): object;
}