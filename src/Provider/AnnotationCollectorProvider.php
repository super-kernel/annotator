<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Provider;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;
use RuntimeException;
use SuperKernel\Annotator\Annotation\ClassAnnotation;
use SuperKernel\Annotator\Annotation\ClassConstantAnnotation;
use SuperKernel\Annotator\Annotation\MethodAnnotation;
use SuperKernel\Annotator\Annotation\PropertyAnnotation;
use SuperKernel\Annotator\AnnotationCacheCollector;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Contract\AnnotationInterface;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\PackageCollectorInterface;
use SuperKernel\Contract\PackageInterface;
use SuperKernel\Contract\PathResolverInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use SuperKernel\ProcessHandler\Contract\ProcessHandlerInterface;
use Throwable;
use function array_push;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function method_exists;
use function mkdir;
use function printf;
use function serialize;
use function str_replace;
use function unserialize;

#[
	Provider(AnnotationCollectorInterface::class),
	Factory,
]
final class AnnotationCollectorProvider
{
	private static AnnotationCollectorInterface $annotationCollector;

	private readonly string $vendorDir;

	private readonly PathResolverInterface $cacheDir;

	public function __construct(
		private readonly PathResolverInterface        $pathResolver,
		private readonly ProcessHandlerInterface      $processHandler,
		private readonly PackageCollectorInterface    $packageCollector,
		private readonly ReflectionCollectorInterface $reflectionCollector,
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
				$attributeMetadataCacheCollector = $this->makeAttributeMetadata($package);
				file_put_contents($cacheFile, serialize($attributeMetadataCacheCollector));
			}
		});

		return $this->loadAttributeMetadataCacheCollector($cacheFile)?->getAttributes();
	}

	private function makeAttributeMetadata(PackageInterface $package): AnnotationCacheCollector
	{
		$annotations = [];
		foreach ($package->getClassmap() as $class => $path) {
			try {
				$reflectClass = $this->reflectionCollector->reflectClass($class);

				$this->collectAnnotations($annotations, $reflectClass);
				$this->collectAnnotations($annotations, $reflectClass->getMethods());
				$this->collectAnnotations($annotations, $reflectClass->getProperties());
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
			...$annotations,
		);
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

	private function collectAnnotations(array &$annotations, array|Reflector $reflector): void
	{
		$reflectors = $reflector instanceof Reflector ? [$reflector] : $reflector;

		foreach ($reflectors as $reflector) {
			if (!method_exists($reflector, 'getAttributes')) {
				continue;
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

	public function __invoke(): AnnotationCollectorInterface
	{
		if (!isset(self::$annotationCollector)) {
			$attributeMetadataCollection = [];
			foreach ($this->packageCollector->getAllPackages() as $package) {
				$attributesMetadata = $this->getAttributeMetadata($package);

				if (is_array($attributesMetadata)) {
					array_push($attributeMetadataCollection, ...$attributesMetadata);
				}
			}

			self::$annotationCollector = new AnnotationCollector($this->reflectionCollector, ...$attributeMetadataCollection);

			// Manually free up memory to help the garbage collector (GC) collect it quickly.
			unset($attributeMetadataCollection);
		}
		return self::$annotationCollector;
	}
}