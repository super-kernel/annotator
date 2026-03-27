<?php
declare(strict_types=1);

namespace SuperKernel\Annotator\Provider;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Annotator\Factory\AnnotationCollectorFactory;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AnnotationCollectorInterface;

#[
	Provider(AnnotationCollectorInterface::class),
	Factory,
]
final class AnnotationCollectorProvider
{
	private static AnnotationCollectorInterface $annotationCollector;

	/**
	 * @param ContainerInterface $container
	 *
	 * @return AnnotationCollectorInterface
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(ContainerInterface $container): AnnotationCollectorInterface
	{
		if (!isset(self::$annotationCollector)) {
			self::$annotationCollector = $container->get(AnnotationCollectorFactory::class)->create();
		}
		return self::$annotationCollector;
	}
}