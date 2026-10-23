# SuperKernel Annotator

`super-kernel/annotator` scans package-scoped class maps supplied through
`SuperKernel\Contract\PackageManagerInterface`, reflects eligible class-like symbols through
`SuperKernel\Contract\ReflectorInterface`, and collects native PHP Attributes declared on classes, methods, and
properties. The vendor directory and reflection implementations are injected through shared Contract interfaces.
Isolated callback execution is injected through `SuperKernel\ScanIsolation\Contract\ScanProcessorInterface`, provided by the
direct `super-kernel/scan-isolation` dependency. This package does not select a concrete scan processor.

## Responsibilities and namespaces

Production classes are organized by their role:

| Namespace | Classes | Responsibility |
| --- | --- | --- |
| `SuperKernel\Annotator\Scan` | `AnnotationScanner`, `ClassAnnotationScanner` | Package traversal and native class/member Attribute extraction. |
| `SuperKernel\Annotator\Scan` | `AnnotationScanWorker`, `AnnotationScanSession` | Isolated callback entry and the scan-to-publication lifecycle. |
| `SuperKernel\Annotator\Cache` | `AnnotationCache`, `SerializedPackageAnnotations`, `SerializedPackageReferences` | Cache files, serialized records, and restoration validation. |
| `SuperKernel\Annotator` | `AnnotationCollector` | Exact and assignable method discovery through the shared collector contract. |

`ClassAnnotationScanner` remains the shared extraction entry for DI bootstrap. `AnnotationScanWorker` remains a
provider callback implementation; its placement under `Scan` does not change who owns process execution.
`Model` continues to hold the collected metadata values, and `Provider` continues to own the DI factory.

Persisted class names and object layouts must match the installed code. Rebuild annotation snapshots through a
normal scan before restore-only deployment after model changes; scanning preserves unrelated files.

## Provider lifecycle

Invoking `AnnotationCollectorProvider` performs the complete lifecycle synchronously:

1. require the supplied Scan Processor's `support()` probe to return `true`;
2. create a static anonymous callback over the supplied Package Manager, Reflector, and vendor directory;
3. execute that callback through the supplied Scan Processor;
4. let the completed child-side scan session publish its data from its destructor; and
5. restore the published package records into an immutable `AnnotationCollector` in the calling process.

Cache operations use `.cache/` beneath the injected `VendorDirInterface` directory and create it on demand.

An enclosing DI bootstrap supplies `PhaseScanProcessor`: its false phase runs the worker in the existing isolated
process, and its true phase restores the published records. Native extraction is shared with DI through
`Scan\ClassAnnotationScanner`, so the finite bootstrap graph and complete package scans use the same Attribute semantics.

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

`getClassesWithAttribute()` returns an insertion-ordered map from every class carrying the exact Attribute name to only
the matching `AttributeMetadata` entries. Consumers performing discovery should use this indexed lookup instead of
repeatedly traversing every package and every unrelated class Attribute. Every entry contains an already constructed,
trusted instance of the exact queried Attribute. Native target and repeatability errors fail during construction and
therefore never appear in a published record; valid repeatable declarations retain their source order.

`getClassesWithAttributeAssignableTo()` returns the same shape but includes declarations whose concrete Attribute
class extends the requested class or implements the requested interface. Assignability is captured during isolated
scanning and restored from metadata, so consumers do not need to repeat reflective family discovery in the application
process. Exact queries remain exact and are unaffected by this additional index.

### Method discovery

`AnnotationCollectorInterface` includes `getMethodsWithAttribute()` and
`getMethodsWithAttributeAssignableTo()`. Both return maps keyed by declaring class and exact method name, with an
ordered non-empty list of matching trusted metadata for each method. The concrete collector builds both indexes from
package records during construction or restoration. Queries do not rescan, reconstruct Attributes, or load targets.
The first package owning a class's method records wins before filtering, including an empty first map. Inherited
methods remain under their declaring class; repeatable declarations retain order and instance identity.

```php
use SuperKernel\Attribute\Provision;
use SuperKernel\Contract\AnnotationCollectorInterface;

/** @var AnnotationCollectorInterface $collector */
$methods = $collector->getMethodsWithAttribute(Provision::class);
```

Consumers call the injected collector directly and retain their own target validation, return-type interpretation,
service definitions, and registration rules. Method indexes are derived from restored records, not serialized separately.

The provider throws `AnnotationScanException` before allocating cache files when the injected processor is unsupported.
Otherwise, it returns only after the child exits and every cache record has been restored and validated. Supplying a
`NullScanProcessor` explicitly skips the callback and restores existing data only; missing, stale, or malformed data is
rejected. If source inspection, reflection, Attribute construction, or serializable snapshot creation fails for a
class-like symbol, the worker records a structured diagnostic, discards all partial metadata for that class, and
continues with the remaining class map. The published collector contains the successful subset and every skipped-class
diagnostic. Successful worker scans do not print warnings; DI reports from its restored collector as described below. Before reflection, source files without a native PHP
Attribute token are skipped, so loading unrelated optional integrations cannot terminate collection because one of
their runtime dependencies is absent. A missing per-package class map remains a structural scan failure.

## Scan diagnostics

`AnnotationCollector` and `Model\PackageAnnotations` additionally implement
`SuperKernel\Contract\ScanDiagnosticsInterface::getDiagnostics(): array`. This capability is not separately bound in DI.
A package exposes its subset; the collector combines diagnostic-capable packages in scan order. Independent package implementations
contribute diagnostics only when they implement that capability.

Every row has exactly these fields:

| Field | Value |
| --- | --- |
| `sequence` | Positive integer, globally contiguous from 1 through N for one complete scan. |
| `package` | Normalized non-empty Composer package name, equal to the containing record's owner. |
| `class` | Non-empty captured class-name string; not an autoload request. |
| `exception_type` | Non-empty concrete throwable-name string; anonymous names may contain NUL. |
| `message` | Original string bytes, including empty messages, control bytes, and non-UTF-8 content. |

No Throwable, trace, resource, timestamp, logger, or output-state flag is serialized. Diagnostic strings can contain
paths or application data; neither caching nor structured logging redacts them. Retrieval never reflects or loads
these captured names.

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

The normal DI bootstrap publishes and reads the complete collector in the **scanning child**, constructs a container
from it, and resolves the application's actual `StdoutLoggerInterface`. It reports one WARNING summary and one INFO
per skipped class. The normal parent does not resolve a logger for these diagnostics. A restore-only Phar has no child:
it reports the cached build diagnostics in its current process each time a new root is created. Future binary hosts
must adapt their bootstrap separately; this change does not implement binary execution.

With the default Logger INFO threshold, summary and details are visible. For example, excluding the logger timestamp:

```text
[WARNING] Annotation scan skipped 1 class.
[INFO] Annotation scan skipped App\Unavailable: Error: Optional parent class is unavailable
```

Plural summary: `Annotation scan skipped %d classes.` Zero skipped classes produce no records and do not resolve a
logger. INFO messages contain all three diagnostic fields because ConsoleFormatter does not render context. The
message's display copy escapes ASCII controls (`\r`, `\n`, `\t`, `\x00`, `\x1B`, `\x7F`); context and caches retain
raw bytes. Ordinary namespace backslashes remain unchanged. See [DI reporting](https://github.com/super-kernel/di/blob/main/README.md#scan-diagnostic-reporting)
for context keys, failure behavior, and process streams, and [Logger configuration](https://github.com/super-kernel/logger/blob/main/README.md#scan-diagnostics)
for a complete application Provider example.

### Direct scanning

`AnnotationScanner` accepts Package Manager, Reflector, and an optional `diagnosticReporter` Closure. The reporter
receives one structured diagnostic row for each skipped class. Without a reporter, `DiagnosticOutput` immediately
writes the yellow warning to STDERR. Supplying a reporter gives the caller responsibility for output; rows are still
retained in the resulting package records.

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

`Diagnostic\DiagnosticOutput::formatWarning(array $diagnostic): string` returns the emergency ANSI warning without its
terminal newline; `writeToStderr(array $diagnostics): void` writes those lines in order and throws if writing fails.
These stateless emergency helpers have no DI binding. A custom reporter owns output for its rows.
Direct `AnnotationCollectorProvider` users receive persisted
diagnostics without successful-scan output and must explicitly consume this capability or use DI's normal bootstrap.

### Schema 4 and failures

Package records now use **schema 4**. Diagnostics share each package's existing atomic `.ser` publication; the
`packages.ser` reference index stays version-free. Write and read validate exact row keys and scalar types, package
ownership, ordered positive sequences, globally unique contiguous sequences, no repeated failed class within a package,
and no partial metadata for that class. The outer allow-list stays exactly `SerializedPackageAnnotations`,
`PackageAnnotations`, and `AttributeMetadata`; diagnostic names never expand it. Nested Attribute snapshot validation
is unchanged. Invalid rows reject restoration rather than being dropped.

**Schema 3 is rejected even if no warning was previously observed.** Rebuild by a normal source scan before packaging
or restoring. Do not share a cache between old and new runtime versions; rebuild matching artifacts when rolling back.
Phar deployments must carry compatible caches and paths, and replay their build diagnostics on each startup without
rescanning or clearing them. Existing per-file atomicity is not a transaction across packages; serialize initialization.

A structural scan failure flushes already collected diagnostics in the yellow STDERR emergency format before rethrowing the
original failure. Publication failure is covered by the same worker guard, including actual Session destruction.
Incomplete results are invalidated; cleanup or emergency-output failures cannot replace the primary exception.
A later package restoration failure flushes only previously validated diagnostics before rethrowing. No logger is
created on these failed scan/read paths. Lost writes to a broken STDERR cannot be guaranteed, but the operation does
not become successful because fallback failed.

## DI factory provider

`SuperKernel\Annotator\Provider\AnnotationCollectorProvider` exposes the collector through DI
`FactoryProvider` metadata:

```php
#[SuperKernel\Attribute\FactoryProvider(
    SuperKernel\Contract\AnnotationCollectorInterface::class,
)]
```

The provider requires a Package Manager, Reflector, and vendor directory through their `SuperKernel\Contract`
interfaces, plus a `SuperKernel\ScanIsolation\Contract\ScanProcessorInterface`. Selecting and constructing those
implementations is the responsibility of the DI integration. The same provider can be invoked manually, as shown
above, when those four dependencies are available. The fourth argument is `VendorDirInterface`.

The provider passes only a `static` anonymous `Closure` to the Scan Processor. Annotator's scan worker remains an
internal callback implementation, while any object used to wrap and terminate that callback belongs entirely to Scan
Isolation and is not exposed through this package's constructor or public contracts.

Each `__invoke()` call performs a new complete synchronous scan and restore lifecycle and returns that invocation's
collector. The provider does not cache its product. Callers that need reuse must retain the returned collector, while
a DI container may retain the mapped entry according to its own lifecycle. Hosts must continue to serialize competing
invocations.

The `FactoryProvider` Attribute comes from the implementation-independent `super-kernel/attribute` package rather
than the official DI implementation. Annotator depends directly on Scan Isolation for its processor interface and has
no production dependency on Composer, Reflector, or DI. Executing this provider requires an integration that
supplies the declared interfaces.

## Collected data

`Model\AttributeMetadata<T>` implements `SuperKernel\Contract\AttributeMetadataInterface<T>`, and
`Model\PackageAnnotations` implements `SuperKernel\Contract\PackageAnnotationsInterface`. Shared collector results
use these read interfaces, allowing custom collectors to supply independent metadata implementations. Business
consumers depend on the shared interfaces while retaining their own registration and validation rules.

`AnnotationCollector` accepts `PackageAnnotationsInterface` values, stores shared metadata interfaces in its indexes,
and declares the same `?PackageAnnotationsInterface` return type as the shared collector contract. All array lookup
types are inherited from the shared interfaces. It retains the supplied package and metadata instances directly,
including independent implementations. Scanning and persistence continue to own the concrete Annotator models.

Arbitrary shared-interface implementations do not become cache-eligible. Serialization remains an Annotator
implementation responsibility. Schema 3 records, including those predating these interfaces, now require rebuilding
because they contain no skipped-class diagnostics.

Each `AttributeMetadata<T>` contains a trusted `T` instance plus the concrete Attribute class name and captured
assignable type names. `attribute()` returns that instance directly, so consumers do not reconstruct it or repeat
constructor validation. The instance is also captured as a serialized snapshot when metadata is created; an Attribute
whose object graph cannot be serialized and restored, or whose snapshot traversal exceeds 64 nested levels, is
excluded through the same per-class warning path.

Exact queries guarantee an instance of the requested class. Assignable queries guarantee an instance compatible with
the requested base class or interface. Consumers remain responsible for relationships in their own domain, such as a
marked command extending Symfony `Command` or a mapped middleware implementing PSR-15, but should not revalidate the
collector's query match or reconstruct the Attribute.

Only members declared by the reflected class are stored. Inherited methods and properties are collected with their own
declaring classes instead of being duplicated under every descendant.

## Runtime layout

The injected `VendorDirInterface` determines the cache root, including custom vendor directories:

```text
<vendor>/.cache/
├── packages.ser
└── annotations/
    ├── super-kernel/composer.ser
    ├── super-kernel/reflector.ser
    └── vendor/package.ser
```

Each package file is the raw serialization of one validated package annotation record. `packages.ser` is published
last and is the raw serialized stub mapping every current package name to the exact nullable `reference()` value from
the injected package metadata. The outer record uses a narrow Annotator-model allow-list. Each metadata value keeps its
Attribute snapshot as a nested serialized payload, preflights it with ordinary object classes disabled, derives its
exact object-class set, and then restores only those classes. Parent restoration therefore loads Attribute value
classes, including enum classes required by PHP serialization, while annotated application classes remain outside the
cache object graph. These files are trusted local runtime artifacts and must not be populated by an untrusted writer.

The files are disposable derived data. The provider rescans on every invocation; package references validate that the
restored files belong to the scan that just completed. The reference stub has no schema version: restoration validates
its structure and exact package references. A failed scan invalidates the previous serialized results and cannot publish
an earlier result from the same session. Deserialization or validation failures reject the stored data; the next normal
scan rebuilds it. Incompatible reference stubs require a normal rescan. Each file is published
atomically through a temporary file in the same directory. The host must still serialize competing provider invocations;
the cache provides no process lock.

Invalidation removes only `.cache/packages.ser` and `.cache/annotations/`; Composer's sibling `.cache/classes/`
directory is preserved. Only the documented cache paths are read or written. A normal
scan populates this layout; restore-only execution requires those files to have been prepared beforehand.

## Development

```bash
composer84 install
composer84 validate --strict
composer84 test
composer84 format:check
composer84 format
```
