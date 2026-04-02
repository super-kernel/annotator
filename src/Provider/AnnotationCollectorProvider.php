<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Provider;

use RuntimeException;
use SuperKernel\Annotator\Annotation\ClassAnnotation;
use SuperKernel\Annotator\Annotation\ClassConstantAnnotation;
use SuperKernel\Annotator\Annotation\MethodAnnotation;
use SuperKernel\Annotator\Annotation\PropertyAnnotation;
use SuperKernel\Annotator\AnnotationCacheCollector;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\AnnotationExtractor;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\PackageCollectorInterface;
use SuperKernel\Contract\PackageInterface;
use SuperKernel\Contract\PathResolverInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use SuperKernel\ProcessHandler\Contract\ProcessHandlerInterface;
use function array_push;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function mkdir;
use function serialize;
use function str_replace;
use function unserialize;

#[
	Provider(AnnotationCollectorInterface::class),
	Factory,
]
final readonly class AnnotationCollectorProvider
{
	private string $vendorDir;

	private PathResolverInterface $cacheDir;

	public function __construct(
		PathResolverInterface                $pathResolver,
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

	private function getAttributeMetadata(PackageInterface $package): ?array
	{
		$packageName = $package->getName() ?? 'root';
		$cacheFile = $this->getCacheFile($packageName);

		$this->processHandler->execute(function () use ($packageName, $cacheFile, $package) {
			$cacheAttributeMetadata = $this->loadAttributeMetadataCacheCollector($cacheFile);
			$reference = $cacheAttributeMetadata?->getReference();

			if (
				null === $reference ||
				$reference !== $package->getReference()
			) {
				$annotations = new AnnotationExtractor(
					$package->getClassAutoloader(),
					$this->reflectionCollector,
				)->getAnnotations();

				$attributeMetadataCacheCollector = new AnnotationCacheCollector(
					   $package->getName(),
					   $package->getReference(),
					...$annotations,
				);

				file_put_contents($cacheFile, serialize($attributeMetadataCacheCollector));
			}
		});

		return $this->loadAttributeMetadataCacheCollector($cacheFile)?->getAttributes();
	}

	private function loadAttributeMetadataCacheCollector(string $cacheFile): ?AnnotationCacheCollector
	{
		if (file_exists($cacheFile)) {
			$annotationCacheCollector = unserialize(
				data   : @file_get_contents($cacheFile),
				options: [
					         'allowed_classes' => [
						         ClassAnnotation::class,
						         MethodAnnotation::class,
						         PropertyAnnotation::class,
						         ClassConstantAnnotation::class,
						         AnnotationCacheCollector::class,
					         ],
				         ],
			);
			if ($annotationCacheCollector instanceof AnnotationCacheCollector) {
				return $annotationCacheCollector;
			}
		}

		return null;
	}

	private function getCacheFile(?string $packageName = null): string
	{
		$fileName = str_replace(['/', '\\'], '_', $packageName);

		return $this->cacheDir->to("$fileName.cache")->get();
	}

	public function __invoke(): AnnotationCollectorInterface
	{
		$attributeMetadataCollection = [];
		foreach ($this->packageCollector->getAllPackages() as $package) {
			$attributesMetadata = $this->getAttributeMetadata($package);

			if (is_array($attributesMetadata)) {
				array_push($attributeMetadataCollection, ...$attributesMetadata);
			}
		}

		$annotationCollector = new AnnotationCollector(...$attributeMetadataCollection);

		// Manually free up memory to help the garbage collector (GC) collect it quickly.
		unset($attributeMetadataCollection);

		return $annotationCollector;
	}
}