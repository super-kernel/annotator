<?php
declare(strict_types=1);

namespace SuperKernel\Annotator;

use SuperKernel\Contract\AnnotationInterface;

final readonly class AnnotationCacheCollector
{
	private array $attributeMetadataCollection;

	public function __construct(private string $name, private ?string $reference, AnnotationInterface ...$attributeMetadataCollection)
	{
		$this->attributeMetadataCollection = $attributeMetadataCollection;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function getReference(): ?string
	{
		return $this->reference;
	}

	/**
	 * @return array<AnnotationInterface>
	 */
	public function getAttributes(): array
	{
		return $this->attributeMetadataCollection;
	}
}