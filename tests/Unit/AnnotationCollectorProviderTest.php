<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Unit;

use ReflectionClass;
use ReflectionFunction;
use SuperKernel\Annotator\Exception\AnnotationScanException;
use SuperKernel\Annotator\Provider\AnnotationCollectorProvider;
use SuperKernel\Annotator\Scan\AnnotationScanner;
use SuperKernel\Annotator\Tests\Fixture\AnnotatedFixture;
use SuperKernel\Annotator\Tests\Support\CallbackScanProcessor;
use SuperKernel\Annotator\Tests\Support\TemporaryVendorDirectory;
use SuperKernel\Annotator\Tests\Support\TestCase;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Contract\VendorDirInterface;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use Throwable;

use function dirname;

final class AnnotationCollectorProviderTest extends TestCase
{
    public function testDeclaresSharedFactoryProviderMetadata(): void
    {
        $reflection = new ReflectionClass(AnnotationCollectorProvider::class);
        $attributes = $reflection->getAttributes();
        $constructor = $reflection->getConstructor();
        $invoke = $reflection->getMethod('__invoke');

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertCount(1, $attributes);
        self::assertSame(FactoryProvider::class, $attributes[0]->getName());
        self::assertSame([
            AnnotationCollectorInterface::class,
        ], $attributes[0]->getArguments());
        self::assertSame(
            AnnotationCollectorInterface::class,
            $attributes[0]->newInstance()->className,
        );
        self::assertNull($constructor);
        self::assertSame(4, $invoke->getNumberOfRequiredParameters());
        self::assertCount(4, $invoke->getParameters());
        $dependencies = [
            PackageManagerInterface::class,
            ScanProcessorInterface::class,
            ReflectorInterface::class,
            VendorDirInterface::class,
        ];

        foreach ($invoke->getParameters() as $position => $parameter) {
            self::assertSame($dependencies[$position], (string) $parameter->getType());
            self::assertFalse($parameter->allowsNull());
            self::assertFalse($parameter->isDefaultValueAvailable());
        }
        self::assertTrue($invoke->isPublic());
        self::assertFalse($invoke->isStatic());
        self::assertSame(AnnotationCollectorInterface::class, (string) $invoke->getReturnType());

        foreach ($reflection->getProperties() as $property) {
            self::assertFalse($property->isStatic());
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testScannerCollectsTheSharedFactoryProviderMetadata(): void
    {
        $package = $this->package('super-kernel/annotator', 'collector-reference');
        $manager = $this->packageManager(
            [
                $package,
            ],
            [
                AnnotationCollectorProvider::class => dirname(
                    __DIR__,
                    2,
                ) . '/src/Provider/AnnotationCollectorProvider.php',
            ],
            [
                AnnotationCollectorProvider::class => $package,
            ],
        );

        $records = new AnnotationScanner($manager, $this->reflector())->scan();

        self::assertCount(1, $records);
        $attributes = $records[0]->classAttributes()[AnnotationCollectorProvider::class];

        self::assertCount(1, $attributes);
        self::assertSame(FactoryProvider::class, $attributes[0]->name());
        self::assertSame(
            AnnotationCollectorInterface::class,
            $attributes[0]->attribute()->className,
        );
    }

    public function testRejectsAnUnsupportedProcessorBeforeStartingTheProviderLifecycle(): void
    {
        $processor = new CallbackScanProcessor(false);
        $provider = new AnnotationCollectorProvider();

        $this->expectException(AnnotationScanException::class);
        $this->expectExceptionMessageIsOrContains('The injected scan processor does not support the current runtime.');

        try {
            $provider(
                $this->createStub(PackageManagerInterface::class),
                $processor,
                $this->createStub(ReflectorInterface::class),
                $this->createStub(VendorDirInterface::class),
            );
        } finally {
            self::assertSame(1, $processor->supportCalls());
            self::assertSame([], $processor->callbacks());
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRunsTheCompleteProviderLifecycleForEveryManualInvocation(): void
    {
        $vendorDir = new TemporaryVendorDirectory();
        $package = $this->package(
            'super-kernel/annotator',
            'collector-reference',
            dirname(__DIR__, 2),
        );
        $class = AnnotatedFixture::class;
        $manager = $this->packageManager(
            [
                $package,
            ],
            [
                $class => dirname(__DIR__) . '/Fixture/AnnotatedFixture.php',
            ],
            owners: [
                $class                             => $package,
                AnnotationCollectorProvider::class => $package,
            ],
        );
        $processor = new CallbackScanProcessor();
        $reflector = $this->reflector();
        $provider = new AnnotationCollectorProvider();

        $first = $provider($manager, $processor, $reflector, $vendorDir);
        $second = $provider($manager, $processor, $reflector, $vendorDir);

        self::assertNotSame($first, $second);
        self::assertSame(2, $processor->supportCalls());
        self::assertNotEmpty($first->getClassAttributes($class));
        self::assertNotEmpty($second->getClassAttributes($class));
        self::assertNotEmpty($vendorDir->files());
        self::assertDirectoryDoesNotExist($vendorDir . '/.runtime');

        $callbacks = $processor->callbacks();

        self::assertCount(2, $callbacks);
        self::assertNotSame($callbacks[0], $callbacks[1]);

        foreach ($callbacks as $callback) {
            $reflection = new ReflectionFunction($callback);

            self::assertTrue($reflection->isClosure());
            self::assertTrue($reflection->isStatic());
            self::assertNull($reflection->getClosureThis());
        }
    }
}
