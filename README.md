<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Annotator

`super-kernel/annotator` restores the native PHP Attributes that `skernel build` collects from the classes, methods, and properties of a SuperKernel project and its installed packages, and answers queries about them through an immutable `AnnotationCollector`. Restoration only reads generated records: it never scans sources, and it never loads the annotated classes.

## Installation

```bash
composer require super-kernel/annotator
```

The package needs PHP 8.4.27 or later in the 8.4 series. The records it restores exist only after `skernel build` has run in the project.

## What it provides

| Declaration                         | Purpose                                                                                                                  |
|-------------------------------------|--------------------------------------------------------------------------------------------------------------------------|
| `AnnotationCollector`               | Implements `SuperKernel\Contract\AnnotationCollectorInterface` and `SuperKernel\Contract\ScanDiagnosticsInterface` over package records. |
| `Cache\AnnotationCache::read()`     | Restores the collector from the records published below the vendor directory.                                            |
| `Cache\AnnotationPublisher`         | Publishes the records; SKERNEL calls it inside the project's own process during `skernel build`.                         |
| `Model\AttributeMetadata`           | One trusted Attribute instance with its captured assignable types.                                                       |
| `Model\PackageAnnotations`          | The class, method, and property Attributes and the skipped-class diagnostics of one package.                             |
| `Exception\AnnotationCacheException` | Raised when records cannot be published or restored.                                                                    |

It does not scan class files, construct Attributes from source, or select which classes are annotated; `skernel build` does that. It supports native PHP Attributes only, not DocBlock annotations.

## Restoring and querying

A SuperKernel container supplies `SuperKernel\Contract\AnnotationCollectorInterface` from these records. Without a container, restore the collector directly:

```php
use SuperKernel\Annotator\Cache\AnnotationCache;

/** @var SuperKernel\Contract\PackageManagerInterface $packages */
/** @var SuperKernel\Contract\VendorDirInterface $vendorDir */
$collector = new AnnotationCache($vendorDir)->read($packages);

$controllers = $collector->getClassesWithAttribute(App\Attribute\Controller::class);
$exceptionHandlers = $collector->getClassesWithAttributeAssignableTo(
    SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler::class,
);
$classAttributes = $collector->getClassAttributes(App\Controller\HomeController::class);
$methodAttributes = $collector->getMethodAttributes(App\Controller\HomeController::class, 'index');
$propertyAttributes = $collector->getPropertyAttributes(App\Service\Clock::class, 'timezone');
```

Each `read()` call restores and validates every record and returns a new collector; it keeps no state between calls.

`getClassesWithAttribute()` returns an insertion-ordered map from every class carrying the exact Attribute name to only the matching `AttributeMetadata` entries. Use this indexed lookup for discovery instead of traversing every package and every unrelated class Attribute. Every entry contains an already constructed, trusted instance of the exact queried Attribute. Native target and repeatability errors fail during construction at build time and therefore never appear in a result; valid repeatable declarations keep their source order.

`getClassesWithAttributeAssignableTo()` returns the same shape but includes declarations whose concrete Attribute class extends the requested class or implements the requested interface. Assignability is captured at build time, so consumers do not repeat reflective family discovery in the application process. Exact queries remain exact.

`getMethodsWithAttribute()` and `getMethodsWithAttributeAssignableTo()` return maps keyed by declaring class and exact method name, with an ordered non-empty list of matching trusted metadata for each method:

```php
use SuperKernel\Attribute\Provision;
use SuperKernel\Contract\AnnotationCollectorInterface;

/** @var AnnotationCollectorInterface $collector */
$methods = $collector->getMethodsWithAttribute(Provision::class);
```

Queries never reconstruct Attributes or load targets. When a class appears in more than one package, the first owning package wins, including an empty first map. Only members declared by a class are recorded: inherited methods and properties stay under their declaring class instead of being duplicated under every descendant. Repeatable declarations keep their order and instance identity.

Exact queries guarantee an instance of the requested class, and assignable queries an instance compatible with the requested base class or interface. Consumers stay responsible for relationships in their own domain, such as a marked command extending Symfony `Command` or a mapped middleware implementing PSR-15, but should not revalidate the query match or reconstruct the Attribute.

## Collected data

`Model\AttributeMetadata<T>` implements `SuperKernel\Contract\AttributeMetadataInterface<T>`, and `Model\PackageAnnotations` implements `SuperKernel\Contract\PackageAnnotationsInterface`. Collector results use these shared interfaces, so custom collectors can supply independent metadata implementations; `AnnotationCollector` retains supplied package and metadata instances directly. Independent implementations are never published.

Each `AttributeMetadata<T>` contains a trusted `T` instance plus the concrete Attribute class name and captured assignable type names. `attribute()` returns that instance directly. The constructor captures the instance as a serialized snapshot and raises `InvalidArgumentException` when its object graph cannot be serialized and restored, when the snapshot nests more than 64 levels deep, or when a captured type is not assignable from the instance. `skernel build` skips such a class with a diagnostic.

## Skipped-class diagnostics

`AnnotationCollector` and `Model\PackageAnnotations` also implement `SuperKernel\Contract\ScanDiagnosticsInterface::getDiagnostics(): array`. When `skernel build` cannot collect the Attributes of a class, it skips that class, prints it, and records one diagnostic row with the owning package. A package exposes its subset, and the collector combines diagnostic-capable packages in build order. Independent package implementations contribute diagnostics only when they implement that capability. The application does not print these rows at start; read them through this capability when needed.

Every row has exactly these fields:

| Field            | Value                                                                                  |
|------------------|----------------------------------------------------------------------------------------|
| `sequence`       | Positive integer, globally contiguous from 1 through N for one complete build.         |
| `package`        | Normalized non-empty Composer package name, equal to the containing record's owner.    |
| `class`          | Non-empty captured class-name string; not an autoload request.                         |
| `exception_type` | Non-empty concrete throwable-name string; anonymous names may contain NUL.             |
| `message`        | Original string bytes, including empty messages, control bytes, and non-UTF-8 content. |

No Throwable, trace, resource, timestamp, logger, or output-state flag is stored. Diagnostic strings can contain paths or application data; the records do not redact them. Retrieval never reflects or loads the captured names.

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

## Publishing records

A build tool that collects Attributes publishes the records with `Cache\AnnotationPublisher`; `skernel build` does this inside the project's own process. `Cache\AnnotationCache::read()` then restores them:

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
- `read()` raises `Exception\AnnotationCacheException` when `packages.ser` is missing, when its references differ from the package manager, or when a record is missing or invalid. It writes nothing to the console.

## Generated files

The injected `VendorDirInterface` determines the location, including custom vendor directories:

```text
<vendor>/.cache/
├── packages.ser
└── annotations/
    ├── super-kernel/metadata.ser
    ├── super-kernel/reflector.ser
    └── vendor/package.ser
```

`Constants\CacheLayout` names the index file (`IndexPath`, `packages.ser`) and the package record directory (`PackageDirectory`, `annotations`) relative to `<vendor>/.cache/`. Each package file holds one validated package record including its diagnostics, and `packages.ser`, published last, maps every current package name to its exact nullable `reference()` value. Restoration loads Attribute value classes, including enum classes, while annotated application classes stay out of the records. These files are trusted local runtime artifacts and must not be populated by an untrusted writer.

The files are derived data that `skernel build` rewrites; ship them with the application, as `skernel package` does. Package references confirm that the records belong to the installed packages. Records that fail deserialization or validation are rejected, not dropped; run `skernel build` again to replace them. Each file is published atomically through a temporary file in the same directory, but publication is not a transaction across packages and provides no process lock. A failed publication removes only `.cache/packages.ser` and `.cache/annotations/`; the sibling files of `super-kernel/metadata` stay in place.

Recorded class names and object layouts must match the installed code, and records with an incompatible schema are rejected. Run `skernel build` again after installing or updating packages, after changing Attributes or annotated classes, and after model changes; do not share the files between installations of different versions. A Phar must carry records built for the code it contains.

## License

Released under the MIT License. See [LICENSE](LICENSE).
