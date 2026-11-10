<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Annotator

`super-kernel/annotator` collects the native PHP Attributes declared on classes, methods, and properties across the package-scoped class maps supplied by `SuperKernel\Contract\PackageManagerInterface`. It scans in an isolated process through `SuperKernel\ScanIsolation\Contract\ScanProcessorInterface`, caches the results beneath the injected `VendorDirInterface` directory, and restores them into an immutable `AnnotationCollector` in the calling process. Reflection uses the injected `ReflectorInterface`. The package does not select a concrete scan processor.

## Collecting annotations

The container supplies `SuperKernel\Contract\AnnotationCollectorInterface` through `SuperKernel\Annotator\Provider\AnnotationCollectorProvider`. The provider can also be invoked directly when its four dependencies are available:

```php
use SuperKernel\Annotator\Provider\AnnotationCollectorProvider;

/** @var SuperKernel\Contract\PackageManagerInterface $packages */
/** @var SuperKernel\ScanIsolation\Contract\ScanProcessorInterface $processor */
/** @var SuperKernel\Contract\ReflectorInterface $reflector */
/** @var SuperKernel\Contract\VendorDirInterface $vendorDir */
$provider = new AnnotationCollectorProvider();
$collector = $provider($packages, $processor, $reflector, $vendorDir);

$controllers = $collector->getClassesWithAttribute(App\Attribute\Controller::class);
$exceptionHandlers = $collector->getClassesWithAttributeAssignableTo(
    SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler::class,
);
$classAttributes = $collector->getClassAttributes(App\Controller\HomeController::class);
$methodAttributes = $collector->getMethodAttributes(App\Controller\HomeController::class, 'index');
$propertyAttributes = $collector->getPropertyAttributes(App\Service\Clock::class, 'timezone');
```

Each invocation performs a new complete, synchronous scan in isolation, publishes the cache, and restores and validates every record before returning that invocation's collector. The provider does not cache its product: callers that need reuse retain the returned collector, and a DI container may retain the mapped entry according to its own lifecycle. Hosts must serialize competing invocations.

- An unsupported processor raises `AnnotationScanException` before any cache file is allocated.
- `NullScanProcessor` skips the scan and restores existing data only; missing, stale, or malformed data is rejected.
- If source inspection, reflection, Attribute construction, or snapshot creation fails for a class-like symbol, the scan records a structured diagnostic, discards all partial metadata for that class, and continues. The collector contains the successful subset and every skipped-class diagnostic.
- Source files without a native PHP Attribute token are skipped before reflection, so an unrelated optional integration whose runtime dependency is absent cannot stop collection.
- A missing per-package class map is a structural scan failure.

## Querying

`getClassesWithAttribute()` returns an insertion-ordered map from every class carrying the exact Attribute name to only the matching `AttributeMetadata` entries. Use this indexed lookup for discovery instead of traversing every package and every unrelated class Attribute. Every entry contains an already constructed, trusted instance of the exact queried Attribute. Native target and repeatability errors fail during construction and therefore never appear in a result; valid repeatable declarations keep their source order.

`getClassesWithAttributeAssignableTo()` returns the same shape but includes declarations whose concrete Attribute class extends the requested class or implements the requested interface. Assignability is captured during the isolated scan, so consumers do not repeat reflective family discovery in the application process. Exact queries remain exact.

`getMethodsWithAttribute()` and `getMethodsWithAttributeAssignableTo()` return maps keyed by declaring class and exact method name, with an ordered non-empty list of matching trusted metadata for each method:

```php
use SuperKernel\Attribute\Provision;
use SuperKernel\Contract\AnnotationCollectorInterface;

/** @var AnnotationCollectorInterface $collector */
$methods = $collector->getMethodsWithAttribute(Provision::class);
```

Queries never rescan, reconstruct Attributes, or load targets. When a class appears in more than one package, the first owning package wins, including an empty first map. Only members declared by the reflected class are stored: inherited methods and properties stay under their declaring class instead of being duplicated under every descendant. Repeatable declarations keep their order and instance identity.

Exact queries guarantee an instance of the requested class, and assignable queries an instance compatible with the requested base class or interface. Consumers stay responsible for relationships in their own domain, such as a marked command extending Symfony `Command` or a mapped middleware implementing PSR-15, but should not revalidate the query match or reconstruct the Attribute.

## Collected data

`Model\AttributeMetadata<T>` implements `SuperKernel\Contract\AttributeMetadataInterface<T>`, and `Model\PackageAnnotations` implements `SuperKernel\Contract\PackageAnnotationsInterface`. Collector results use these shared interfaces, so custom collectors can supply independent metadata implementations; `AnnotationCollector` retains supplied package and metadata instances directly. Independent implementations are never written to the cache.

Each `AttributeMetadata<T>` contains a trusted `T` instance plus the concrete Attribute class name and captured assignable type names. `attribute()` returns that instance directly. The instance is captured as a serialized snapshot when metadata is created: an Attribute whose object graph cannot be serialized and restored, or whose snapshot nests more than 64 levels deep, excludes its class through the skipped-class diagnostic.

## Scan diagnostics

`AnnotationCollector` and `Model\PackageAnnotations` also implement `SuperKernel\Contract\ScanDiagnosticsInterface::getDiagnostics(): array`. A package exposes its subset, and the collector combines diagnostic-capable packages in scan order. Independent package implementations contribute diagnostics only when they implement that capability.

Every row has exactly these fields:

| Field            | Value                                                                                  |
|------------------|----------------------------------------------------------------------------------------|
| `sequence`       | Positive integer, globally contiguous from 1 through N for one complete scan.          |
| `package`        | Normalized non-empty Composer package name, equal to the containing record's owner.    |
| `class`          | Non-empty captured class-name string; not an autoload request.                         |
| `exception_type` | Non-empty concrete throwable-name string; anonymous names may contain NUL.             |
| `message`        | Original string bytes, including empty messages, control bytes, and non-UTF-8 content. |

No Throwable, trace, resource, timestamp, logger, or output-state flag is stored. Diagnostic strings can contain paths or application data; neither caching nor structured logging redacts them. Retrieval never reflects or loads the captured names.

```php
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Contract\ScanDiagnosticsInterface;

// Hand-built metadata without skipped-class diagnostics.
$record = new PackageAnnotations('app/example', null, [], [], []);

// Optional sixth argument carries the package's diagnostics.
$package = new PackageAnnotations('app/example', null, [], [], [], [
    [
        'sequence' => 1,
        'package' => 'app/example',
        'class' => 'App\\Unavailable',
        'exception_type' => 'Error',
        'message' => 'Optional parent class is unavailable',
    ],
]);
$collector = new AnnotationCollector([$package]);
if ($collector instanceof ScanDiagnosticsInterface) {
    $diagnostics = $collector->getDiagnostics();
}
```

The normal DI bootstrap reports these diagnostics through the application's `StdoutLoggerInterface`: one WARNING summary and one INFO record per skipped class, once per root. With the default Logger INFO threshold both are visible; for example, excluding the logger timestamp:

```text
[WARNING] Annotation scan skipped 1 class.
[INFO] Annotation scan skipped App\Unavailable: Error: Optional parent class is unavailable
```

Plural summary: `Annotation scan skipped %d classes.` Zero skipped classes produce no records and do not resolve a logger. INFO messages contain all three diagnostic fields because ConsoleFormatter does not render context. The message's display copy escapes ASCII controls (`\r`, `\n`, `\t`, `\x00`, `\x1B`, `\x7F`); context and caches keep the raw bytes. Ordinary namespace backslashes remain unchanged. See [DI scan diagnostics](https://github.com/super-kernel/di/blob/main/README.md#scan-diagnostics) for context keys, failure behavior, and process streams, and [Logger configuration](https://github.com/super-kernel/logger/blob/main/README.md#scan-diagnostics) for a complete application Provider example. Direct `AnnotationCollectorProvider` callers receive the persisted diagnostics without output and must read this capability themselves.

### Direct scanning

`Scan\AnnotationScanner` accepts the package manager, the reflector, and an optional `diagnosticReporter` Closure that receives one diagnostic row for each skipped class. Without a reporter, each diagnostic is written immediately to STDERR as a yellow warning. Supplying a reporter makes the caller responsible for output; the rows are still kept in the resulting package records.

```php
use SuperKernel\Annotator\Scan\AnnotationScanner;

$scanner = new AnnotationScanner($packages, $reflector);
$records = $scanner->scan();

$diagnostics = [];
$scanner = new AnnotationScanner(
    $packages,
    $reflector,
    diagnosticReporter: static function (array $diagnostic) use (&$diagnostics): void {
        $diagnostics[] = $diagnostic;
    },
);
$records = $scanner->scan();
```

`Diagnostic\DiagnosticOutput::formatWarning(array $diagnostic): string` returns that ANSI warning without its terminal newline; `writeToStderr(array $diagnostics): void` writes the lines in order and throws if writing fails. These stateless helpers have no DI binding.

## Publishing and restoring without scanning

A build tool that collects Attributes itself publishes the records with `Cache\AnnotationPublisher`. `Cache\AnnotationCache::read()` then restores the collector without a scan processor and never scans:

```php
use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Cache\AnnotationPublisher;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;

/** @var SuperKernel\Contract\PackageManagerInterface $packages */
/** @var SuperKernel\Contract\VendorDirInterface $vendorDir */
$records = [];

foreach ($packages->all() as $package) {
    $records[] = new PackageAnnotations($package->name(), $package->reference(), [], [], []);
}

$records[0] = new PackageAnnotations($records[0]->packageName(), $records[0]->reference(), [
    App\Controller\HomeController::class => [
        new AttributeMetadata(new App\Attribute\Controller()),
    ],
], [], []);

new AnnotationPublisher($vendorDir)->publish($packages, $records);
$collector = new AnnotationCache($vendorDir)->read($packages);
```

- `publish()` requires exactly one `Model\PackageAnnotations` record for every package of the package manager, with the same `reference()`. Records may arrive in any order and their class and member maps unsorted; the published files are the same for the same content. Diagnostic sequences run from 1 through N across all records.
- When a record is not a `PackageAnnotations` model, a package has more than one record, or a record does not match the package manager, `publish()` raises `Exception\AnnotationCacheException`. Any failure removes every annotation record before the exception propagates, so a later `read()` fails instead of returning partial data.
- `read()` raises `Exception\AnnotationCacheException` when `packages.ser` is missing, when its references differ from the package manager, or when a record is missing or invalid. It writes the diagnostics validated so far to STDERR before rethrowing.

## Cache files and failures

The injected `VendorDirInterface` determines the cache root, including custom vendor directories:

```text
<vendor>/.cache/
├── packages.ser
└── annotations/
    ├── super-kernel/composer.ser
    ├── super-kernel/reflector.ser
    └── vendor/package.ser
```

`Constants\CacheLayout` names the index file (`IndexPath`, `packages.ser`) and the package record directory (`PackageDirectory`, `annotations`) relative to `<vendor>/.cache/`. Each package file holds one validated package record including its diagnostics, and `packages.ser`, published last, maps every current package name to its exact nullable `reference()` value. Restoration loads Attribute value classes, including enum classes, while annotated application classes stay out of the cache. These files are trusted local runtime artifacts and must not be populated by an untrusted writer.

The files are disposable derived data. Every provider invocation rescans, and package references confirm that restored files belong to the scan that just completed. A failed scan invalidates the previous results. Records that fail deserialization or validation are rejected, not dropped, and the next normal scan rebuilds them. Each file is published atomically through a temporary file in the same directory, but publication is not a transaction across packages and the cache provides no process lock. Invalidation removes only `.cache/packages.ser` and `.cache/annotations/`; Composer's sibling `.cache/classes/` directory is preserved.

Cached class names and object layouts must match the installed code, and records with an incompatible schema are rejected. Rebuild through a normal source scan before packaging or restore-only deployment, and after model changes; do not share a cache between runtime versions, and rebuild matching artifacts when rolling back. Phar deployments must carry compatible caches and paths, and they replay the build diagnostics on each startup without rescanning.

A structural scan or publication failure writes the diagnostics collected so far to STDERR in the yellow warning format, invalidates the incomplete results, and rethrows the original failure. A later package restoration failure writes only the diagnostics already validated before rethrowing. Cleanup or output failures never replace the original exception, and no logger is created on these failure paths. Writes to a broken STDERR can be lost, but the operation never becomes successful because the fallback failed.
