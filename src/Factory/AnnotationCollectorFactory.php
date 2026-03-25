<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Factory;

use ReflectionMethod;
use Reflector;
use RuntimeException;
use SuperKernel\Annotator\Annotation;
use SuperKernel\Annotator\AnnotationCacheCollector;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AnnotationInterface;
use SuperKernel\Contract\PackageCollectorInterface;
use SuperKernel\Contract\PackageInterface;
use SuperKernel\Contract\PathResolverInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use SuperKernel\ProcessHandler\Contract\ProcessHandlerInterface;
use Throwable;
use function array_filter;
use function array_merge;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function method_exists;
use function mkdir;
use function printf;
use function serialize;
use function str_replace;
use function unserialize;

final readonly class AnnotationCollectorFactory
{
	private string $vendorDir;

	private PathResolverInterface $cacheDir;

	public function __construct(
		private PathResolverInterface        $pathResolver,
		private ProcessHandlerInterface      $processHandler,
		private PackageCollectorInterface    $packageCollector,
		private ReflectionCollectorInterface $reflectionCollector,
	)
	{
		$this->vendorDir = $this->packageCollector->getRootPackage()->getRawData()['config']['vendor-dir'] ?? 'vendor';
		$this->cacheDir = $pathResolver->to($this->vendorDir)->to('.super-kernel')->to('attributes');

		$cacheDir = $this->cacheDir->get();
		if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
			throw new RuntimeException("Could not create cache dir: $cacheDir");
		}
	}

	public function create(): AnnotationCollectorInterface
	{
		$attributeMetadataCollection = [];
		foreach ($this->packageCollector->getAllPackages() as $package) {
			$attributesMetadata = $this->getAttributeMetadata($package);
			if (null === $attributesMetadata) {
				continue;
			}

			$attributeMetadataCollection = array_merge($attributeMetadataCollection, $attributesMetadata);
		}

		return new AnnotationCollector(...$attributeMetadataCollection);
	}

	private function getAttributeMetadata(PackageInterface $package): ?array
	{
		$packageName = $package->getName() ?? 'root';
		$cacheFile = $this->getCacheFile($packageName);

		$this->processHandler->execute(function () use ($packageName, $cacheFile, $package) {
			$reference = $package->getReference();
			$cacheAttributeMetadata = $this->loadAttributeMetadataCacheCollector($cacheFile);
			if (
				$packageName !== $cacheAttributeMetadata?->getName()
				|| null === $cacheAttributeMetadata?->getReference()
				|| $reference !== $cacheAttributeMetadata?->getReference()
			) {
				$attributeMetadataCacheCollector = $this->makeAttributeMetadata($package);
				file_put_contents($cacheFile, serialize($attributeMetadataCacheCollector));
			}
		});

		return $this->loadAttributeMetadataCacheCollector($cacheFile)?->getAttributes();
	}

	private function makeAttributeMetadata(PackageInterface $package): AnnotationCacheCollector
	{
		$attributes = [];
		foreach ($package->getClassmap() as $class => $path) {
			try {
				$reflectClass = $this->reflectionCollector->reflectClass($class);

				$attributes = array_merge($attributes, $this->addAttribute($reflectClass));
				$attributes = array_merge($attributes, $this->addAttribute($reflectClass->getMethods()));
				$attributes = array_merge($attributes, $this->addAttribute($reflectClass->getProperties()));
				$attributes = array_merge($attributes, $this->addAttribute($reflectClass->getReflectionConstants()));
			}
			catch (Throwable $throwable) {
				printf("\033[33m[WARNING]\033[0m %s in %s" . PHP_EOL,
				       $throwable->getMessage(),
				       $this->pathResolver->to($path)->get(),
				);
			}
		}

		return new AnnotationCacheCollector(
			   $package->getName(),
			   $package->getReference(),
			...array_filter($attributes, fn($attribute) => $attribute instanceof AnnotationInterface),
		);
	}

	private function loadAttributeMetadataCacheCollector(string $cacheFile): ?AnnotationCacheCollector
	{
		if (file_exists($cacheFile)) {
			$unserializedData = unserialize(
				data   : @file_get_contents($cacheFile),
				options: [
					         'allowed_classes' => [
						         Annotation::class,
						         AnnotationCacheCollector::class,
					         ],
				         ],
			);
			if ($unserializedData instanceof AnnotationCacheCollector) {
				return $unserializedData;
			}
		}

		return null;
	}

	private function getCacheFile(?string $packageName = null): string
	{
		$fileName = str_replace(['/', '\\'], '_', $packageName);

		return $this->cacheDir->to("$fileName.cache")->get();
	}

	private function addAttribute(array|Reflector $reflector): array
	{
		$reflectors = $reflector instanceof Reflector ? [$reflector] : $reflector;

		$attributes = [];
		foreach ($reflectors as $reflector) {
			if (!method_exists($reflector, 'getAttributes')) {
				continue;
			}
			foreach ($reflector->getAttributes() as $attribute) {
				$attributes[] = new Annotation($reflector, $attribute);
				if ($reflector instanceof ReflectionMethod) {
					$attributes = array_merge($attributes, $this->addAttribute($reflector->getParameters()));
				}
			}
		}

		return $attributes;
	}
}