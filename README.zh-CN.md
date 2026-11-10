<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Annotator

`super-kernel/annotator` 在 `SuperKernel\Contract\PackageManagerInterface` 提供的、按包划分的类图中，收集类、方法和属性上声明的原生 PHP 注解（Attribute）。它通过 `SuperKernel\ScanIsolation\Contract\ScanProcessorInterface` 在隔离进程中扫描，把结果缓存在注入的 `VendorDirInterface` 目录下，然后在调用方进程中把它们恢复为不可变的 `AnnotationCollector`。反射使用注入的 `ReflectorInterface`。本包不选定具体的扫描处理器。

## 收集注解

容器通过 `SuperKernel\Annotator\Provider\AnnotationCollectorProvider` 提供 `SuperKernel\Contract\AnnotationCollectorInterface`。在具备其四个依赖时，也可以直接调用该提供者：

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

每次调用都会在隔离环境中同步执行一次全新的完整扫描，发布缓存，并在恢复和校验所有记录之后，才返回本次调用的收集器。提供者不会缓存其产品：需要复用的调用方应保留返回的收集器，DI 容器可以按自己的生命周期保留映射的条目。宿主必须把相互竞争的调用串行化。

- 处理器不受支持时，会在分配任何缓存文件之前抛出 `AnnotationScanException`。
- `NullScanProcessor` 跳过扫描，只恢复已有的数据；数据缺失、过期或格式错误时会被拒绝。
- 如果某个类级符号的源码检查、反射、注解构造或快照创建失败，扫描会记录一条结构化诊断，丢弃该类的所有部分元数据，然后继续。收集器包含成功的那部分结果，以及每个被跳过的类的诊断。
- 不含原生 PHP 注解 token 的源文件会在反射之前被跳过，因此某个无关的可选集成即使缺少其运行时依赖，也不会中断收集。
- 某个包缺少类图属于结构性的扫描失败。

## 查询

`getClassesWithAttribute()` 返回一个保持插入顺序的映射，从每个带有该确切注解名的类，指向仅包含匹配项的 `AttributeMetadata` 条目。做发现时请使用这个带索引的查询，而不是遍历每个包和每个无关的类注解。每个条目都包含一个已构造好的、可信的、确切类型的注解实例。原生的目标和可重复性错误会在构造时失败，因此从不会出现在结果中；有效的可重复声明保持其源码顺序。

`getClassesWithAttributeAssignableTo()` 返回相同的形状，但还包括其具体注解类继承了所请求的类、或实现了所请求接口的声明。可赋值关系在隔离扫描期间捕获，因此使用方不必在应用进程中重复进行基于反射的家族发现。精确查询仍然只做精确匹配。

`getMethodsWithAttribute()` 和 `getMethodsWithAttributeAssignableTo()` 返回以声明类和确切方法名为键的映射，每个方法对应一个有序、非空的匹配可信元数据列表：

```php
use SuperKernel\Attribute\Provision;
use SuperKernel\Contract\AnnotationCollectorInterface;

/** @var AnnotationCollectorInterface $collector */
$methods = $collector->getMethodsWithAttribute(Provision::class);
```

查询从不重新扫描、不重建注解，也不加载目标。一个类出现在多个包中时，以第一个拥有它的包为准，即使那个包的映射为空。只保存被反射的类自己声明的成员：继承来的方法和属性留在其声明类下，不会在每个子类下重复。可重复的声明保持其顺序和实例身份。

精确查询保证返回所请求类的实例，可赋值查询保证返回与所请求的基类或接口兼容的实例。使用方仍需负责其自身领域中的关系，例如被标记的命令是否继承 Symfony `Command`、被映射的中间件是否实现 PSR-15，但不应重新校验查询的匹配结果，也不应重建注解。

## 收集到的数据

`Model\AttributeMetadata<T>` 实现 `SuperKernel\Contract\AttributeMetadataInterface<T>`，`Model\PackageAnnotations` 实现 `SuperKernel\Contract\PackageAnnotationsInterface`。收集器的结果使用这些共享接口，因此自定义的收集器可以提供独立的元数据实现；`AnnotationCollector` 会直接保留传入的包和元数据实例。独立的实现从不会被写入缓存。

每个 `AttributeMetadata<T>` 都包含一个可信的 `T` 实例，以及具体的注解类名和捕获到的可赋值类型名。`attribute()` 直接返回该实例。创建元数据时，该实例会被捕获为一份序列化快照：如果某个注解的对象图无法被序列化和恢复，或其快照嵌套超过 64 层，它所在的类会通过跳过类的诊断被排除。

## 扫描诊断

`AnnotationCollector` 和 `Model\PackageAnnotations` 还实现了 `SuperKernel\Contract\ScanDiagnosticsInterface::getDiagnostics(): array`。单个包暴露它自己的子集，收集器按扫描顺序合并所有具备诊断能力的包。独立的包实现只有在实现了这一能力时才会提供诊断。

每一行恰好包含以下字段：

| 字段             | 值                                                    |
|------------------|-------------------------------------------------------|
| `sequence`       | 正整数，在一次完整扫描中从 1 到 N 全局连续。          |
| `package`        | 规范化的非空 Composer 包名，与所在记录的归属包一致。  |
| `class`          | 非空的捕获类名字符串；不会触发自动加载。              |
| `exception_type` | 非空的具体异常类名字符串；匿名类的名称可能包含 NUL。  |
| `message`        | 原始字符串字节，包括空消息、控制字节和非 UTF-8 内容。 |

不保存任何 Throwable、调用栈、资源、时间戳、日志器或输出状态标志。诊断字符串可能包含路径或应用数据；缓存和结构化日志都不会对其脱敏。读取诊断从不反射或加载捕获到的名称。

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

正常的 DI 启动会通过应用的 `StdoutLoggerInterface` 报告这些诊断：一条 WARNING 汇总，以及每个被跳过的类各一条 INFO 记录，每个根容器只报告一次。在 Logger 默认的 INFO 阈值下两者都可见；例如，去掉日志时间戳后：

```text
[WARNING] Annotation scan skipped 1 class.
[INFO] Annotation scan skipped App\Unavailable: Error: Optional parent class is unavailable
```

复数形式的汇总：`Annotation scan skipped %d classes.`。没有被跳过的类时，不产生任何记录，也不会解析日志器。由于 ConsoleFormatter 不渲染 context，INFO 消息中包含全部三个诊断字段。消息的显示副本会转义 ASCII 控制字符（`\r`、`\n`、`\t`、`\x00`、`\x1B`、`\x7F`）；context 和缓存保留原始字节。普通的命名空间反斜杠保持不变。context 键、失败行为和进程输出流参见 [DI 扫描诊断](https://github.com/super-kernel/di/blob/main/README.md#scan-diagnostics)，完整的应用 Provider 示例参见 [Logger 配置](https://github.com/super-kernel/logger/blob/main/README.md#scan-diagnostics)。直接调用 `AnnotationCollectorProvider` 的代码会拿到持久化的诊断，但不会有任何输出，需要自行读取这一能力。

### 直接扫描

`Scan\AnnotationScanner` 接收包管理器、反射器，以及一个可选的 `diagnosticReporter` Closure，该回调为每个被跳过的类接收一行诊断。没有提供回调时，每条诊断都会立即以黄色警告写到 STDERR。提供回调后，输出由调用方负责；这些行仍会保留在生成的包记录中。

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

`Diagnostic\DiagnosticOutput::formatWarning(array $diagnostic): string` 返回不带结尾换行的 ANSI 警告；`writeToStderr(array $diagnostics): void` 按顺序写出这些行，写入失败时抛出异常。这些无状态的辅助方法没有 DI 绑定。

## 发布与免扫描恢复

自行收集 Attribute 的构建工具通过 `Cache\AnnotationPublisher` 发布记录。之后 `Cache\AnnotationCache::read()` 无需扫描处理器即可恢复收集器，并且从不扫描：

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

- `publish()` 要求包管理器中的每个包都恰好有一条 `Model\PackageAnnotations` 记录，且 `reference()` 相同。记录可以以任意顺序到达，其类映射和成员映射可以未排序；相同内容发布出的文件相同。诊断序号在所有记录中从 1 连续到 N。
- 当某条记录不是 `PackageAnnotations` 模型或与包管理器不一致时，`publish()` 抛出 `Exception\AnnotationCacheException`。任何失败都会在异常传播之前删除全部注解记录，因此之后的 `read()` 会失败，而不是返回不完整的数据。
- 当 `packages.ser` 缺失、其 reference 与包管理器不一致，或某条记录缺失或无效时，`read()` 抛出 `Exception\AnnotationCacheException`。它会在重新抛出之前把已经校验过的诊断写到 STDERR。

## 缓存文件与失败

注入的 `VendorDirInterface` 决定缓存根目录，自定义的 vendor 目录也是如此：

```text
<vendor>/.cache/
├── packages.ser
└── annotations/
    ├── super-kernel/composer.ser
    ├── super-kernel/reflector.ser
    └── vendor/package.ser
```

`Constants\CacheLayout` 给出相对于 `<vendor>/.cache/` 的索引文件（`IndexPath`，`packages.ser`）和包记录目录（`PackageDirectory`，`annotations`）的名称。每个包文件保存一条经过校验的包记录（包括其诊断），最后发布的 `packages.ser` 把每个当前包名映射到其确切的、可为 null 的 `reference()` 值。恢复时会加载注解的值类（包括枚举类），而被标注的应用类不会进入缓存。这些文件是可信的本地运行时产物，不得由不受信任的写入方生成。

这些文件是可丢弃的派生数据。每次调用提供者都会重新扫描，包的 reference 用来确认恢复的文件属于刚刚完成的那次扫描。扫描失败会使之前的结果失效。反序列化或校验失败的记录会被拒绝而不是丢弃，下一次正常扫描会重新生成它们。每个文件都通过同目录下的临时文件原子发布，但跨包的发布不是事务，缓存也不提供进程锁。失效只删除 `.cache/packages.ser` 和 `.cache/annotations/`；Composer 的同级目录 `.cache/classes/` 会被保留。

缓存中的类名和对象布局必须与已安装的代码一致，schema 不兼容的记录会被拒绝。请在打包或仅恢复部署之前、以及模型变更之后，通过一次正常的源码扫描重新生成缓存；不要在不同的运行时版本之间共享缓存，回滚时也要重新生成对应的产物。Phar 部署必须携带兼容的缓存和路径，并在每次启动时重放构建期的诊断，而不重新扫描。

结构性的扫描失败或发布失败，会把已收集到的诊断以黄色警告格式写到 STDERR，使不完整的结果失效，然后重新抛出原始失败。之后某个包的恢复失败，只会在重新抛出之前写出已经校验过的诊断。清理或输出的失败从不取代原始异常，在这些失败路径上也不会创建日志器。写入损坏的 STDERR 可能会丢失内容，但操作不会因为回退失败而变成成功。
