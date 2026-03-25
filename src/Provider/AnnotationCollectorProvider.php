<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Provider;

use SuperKernel\Annotator\Factory\AnnotationCollectorFactory;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\PackageCollectorInterface;
use SuperKernel\Contract\PathResolverInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use SuperKernel\ProcessHandler\Contract\ProcessHandlerInterface;

#[
	Provider(AnnotationCollectorInterface::class),
	Factory,
]
final class AnnotationCollectorProvider
{
	private static AnnotationCollectorInterface $annotationCollector;

	public function __invoke(
		PathResolverInterface        $pathResolver,
		ProcessHandlerInterface      $processHandler,
		PackageCollectorInterface    $packageCollector,
		ReflectionCollectorInterface $reflectionCollector,
	): AnnotationCollectorInterface
	{
		if (!isset(self::$annotationCollector)) {
			self::$annotationCollector = new AnnotationCollectorFactory(
				$pathResolver,
				$processHandler,
				$packageCollector,
				$reflectionCollector,
			)->create();
		}
		return self::$annotationCollector;
	}
}