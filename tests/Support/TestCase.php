<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Support;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\PackageMetadataInterface;
use SuperKernel\Contract\ReflectorInterface;
use Throwable;

use function array_filter;

use const ARRAY_FILTER_USE_KEY;

abstract class TestCase extends PHPUnitTestCase
{
    /** @throws Throwable When fixture setup or a supplied test callback fails */
    protected function package(
        string  $name,
        ?string $reference,
        ?string $locatedPath = null,
    ): PackageMetadataInterface
    {
        $package = $this->createStub(PackageMetadataInterface::class);
        $package->method('version')->willReturn(null);
        $package->method('name')->willReturn($name);
        $package->method('reference')->willReturn($reference);
        $package->method('locatedPath')->willReturn($locatedPath);

        return $package;
    }

    /**
     * @param list<PackageMetadataInterface>                $packages
     * @param array<class-string, non-empty-string>         $classMap
     * @param array<class-string, PackageMetadataInterface> $owners
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    protected function packageManager(
        array $packages,
        array $classMap = [],
        array $owners = [],
    ): PackageManagerInterface
    {
        $manager = $this->createStub(PackageManagerInterface::class);
        $manager->method('all')->willReturn($packages);
        $manager->method('classes')->willReturnCallback(
            static function (string $packageName) use ($packages, $classMap, $owners): ?array {
                foreach ($packages as $package) {
                    if ($package->name() !== $packageName) {
                        continue;
                    }

                    if ([] === $owners) {
                        return $classMap;
                    }

                    return array_filter(
                        $classMap,
                        static fn(string $class): bool => ($owners[$class] ?? null) === $package,
                        ARRAY_FILTER_USE_KEY,
                    );
                }

                return null;
            },
        );
        $manager->method('packageForClass')->willReturnCallback(
            static fn(string $class): ?PackageMetadataInterface => $owners[$class] ?? null,
        );

        return $manager;
    }

    /** @throws Throwable When fixture setup or a supplied test callback fails */
    protected function reflector(): ReflectorInterface
    {
        $reflector = $this->createStub(ReflectorInterface::class);
        $reflector->method('reflectClass')->willReturnCallback(
            static fn(object|string $class): ReflectionClass => new ReflectionClass($class),
        );

        return $reflector;
    }
}
