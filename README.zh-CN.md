<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Annotator

`super-kernel/annotator` 还原 `skernel build` 从 SuperKernel 项目及其已安装包的类、方法和属性上收集到的原生 PHP 注解（Attribute），并通过不可变的 `AnnotationCollector` 回答关于它们的查询。还原只读取生成的记录：它从不扫描源码，也从不加载被标注的类。

## 安装

```bash
composer require super-kernel/annotator
```

本包需要 8.4 系列中 8.4.27 及以上版本的 PHP。它还原的记录只有在项目中运行过 `skernel build` 后才存在。

## 提供的内容

| 声明                                | 用途                                                                                                                     |
|-------------------------------------|--------------------------------------------------------------------------------------------------------------------------|
| `AnnotationCollector`               | 基于包记录实现 `SuperKernel\Contract\AnnotationCollectorInterface` 和 `SuperKernel\Contract\ScanDiagnosticsInterface`。   |
| `Cache\AnnotationCache::read()`     | 从发布在 vendor 目录下的记录还原收集器。                                                                                 |
| `Cache\AnnotationPublisher`         | 发布记录；SKERNEL 在 `skernel build` 期间于项目自身的进程中调用它。                                                       |
| `Model\AttributeMetadata`           | 一个可信的注解实例及其捕获到的可赋值类型。                                                                               |
| `Model\PackageAnnotations`          | 一个包的类、方法和属性注解，以及被跳过的类的诊断。                                                                       |
| `Exception\AnnotationCacheException` | 记录无法发布或还原时抛出。                                                                                              |

它不扫描类文件，不从源码构造注解，也不决定哪些类带有注解；这些由 `skernel build` 完成。它只支持原生 PHP 注解，不支持 DocBlock 注解。

## 还原与查询

SuperKernel 容器根据这些记录提供 `SuperKernel\Contract\AnnotationCollectorInterface`。不使用容器时，可以直接还原收集器：

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

每次调用 `read()` 都会还原并校验所有记录，然后返回一个新的收集器；它在两次调用之间不保留任何状态。

`getClassesWithAttribute()` 返回一个保持插入顺序的映射，从每个带有该确切注解名的类，指向仅包含匹配项的 `AttributeMetadata` 条目。做发现时请使用这个带索引的查询，而不是遍历每个包和每个无关的类注解。每个条目都包含一个已构造好的、可信的、确切类型的注解实例。原生的目标和可重复性错误会在构建时的构造阶段失败，因此从不会出现在结果中；有效的可重复声明保持其源码顺序。

`getClassesWithAttributeAssignableTo()` 返回相同的形状，但还包括其具体注解类继承了所请求的类、或实现了所请求接口的声明。可赋值关系在构建时捕获，因此使用方不必在应用进程中重复进行基于反射的家族发现。精确查询仍然只做精确匹配。

`getMethodsWithAttribute()` 和 `getMethodsWithAttributeAssignableTo()` 返回以声明类和确切方法名为键的映射，每个方法对应一个有序、非空的匹配可信元数据列表：

```php
use SuperKernel\Attribute\Provision;
use SuperKernel\Contract\AnnotationCollectorInterface;

/** @var AnnotationCollectorInterface $collector */
$methods = $collector->getMethodsWithAttribute(Provision::class);
```

查询从不重建注解，也不加载目标。一个类出现在多个包中时，以第一个拥有它的包为准，即使那个包的映射为空。只记录类自己声明的成员：继承来的方法和属性留在其声明类下，不会在每个子类下重复。可重复的声明保持其顺序和实例身份。

精确查询保证返回所请求类的实例，可赋值查询保证返回与所请求的基类或接口兼容的实例。使用方仍需负责其自身领域中的关系，例如被标记的命令是否继承 Symfony `Command`、被映射的中间件是否实现 PSR-15，但不应重新校验查询的匹配结果，也不应重建注解。

## 收集到的数据

`Model\AttributeMetadata<T>` 实现 `SuperKernel\Contract\AttributeMetadataInterface<T>`，`Model\PackageAnnotations` 实现 `SuperKernel\Contract\PackageAnnotationsInterface`。收集器的结果使用这些共享接口，因此自定义的收集器可以提供独立的元数据实现；`AnnotationCollector` 会直接保留传入的包和元数据实例。独立的实现从不会被发布。

每个 `AttributeMetadata<T>` 都包含一个可信的 `T` 实例，以及具体的注解类名和捕获到的可赋值类型名。`attribute()` 直接返回该实例。构造函数会把该实例捕获为一份序列化快照；当其对象图无法被序列化和恢复、快照嵌套超过 64 层，或某个捕获的类型不能由该实例赋值时，抛出 `InvalidArgumentException`。`skernel build` 会跳过这样的类并记录一条诊断。

## 被跳过的类的诊断

`AnnotationCollector` 和 `Model\PackageAnnotations` 还实现了 `SuperKernel\Contract\ScanDiagnosticsInterface::getDiagnostics(): array`。当 `skernel build` 无法收集某个类的注解时，它会跳过该类、把它打印出来，并在所属包中记录一行诊断。单个包暴露它自己的子集，收集器按构建顺序合并所有具备诊断能力的包。独立的包实现只有在实现了这一能力时才会提供诊断。应用启动时不会打印这些行；需要时请通过这一能力读取。

每一行恰好包含以下字段：

| 字段             | 值                                                    |
|------------------|-------------------------------------------------------|
| `sequence`       | 正整数，在一次完整构建中从 1 到 N 全局连续。          |
| `package`        | 规范化的非空 Composer 包名，与所在记录的归属包一致。  |
| `class`          | 非空的捕获类名字符串；不会触发自动加载。              |
| `exception_type` | 非空的具体异常类名字符串；匿名类的名称可能包含 NUL。  |
| `message`        | 原始字符串字节，包括空消息、控制字节和非 UTF-8 内容。 |

不保存任何 Throwable、调用栈、资源、时间戳、日志器或输出状态标志。诊断字符串可能包含路径或应用数据；记录不会对其脱敏。读取诊断从不反射或加载捕获到的名称。

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

## 发布记录

收集注解的构建工具通过 `Cache\AnnotationPublisher` 发布记录；`skernel build` 在项目自身的进程中完成这一步。之后由 `Cache\AnnotationCache::read()` 还原它们：

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
- 当某条记录不是 `PackageAnnotations` 模型、某个包有多条记录，或某条记录与包管理器不一致时，`publish()` 抛出 `Exception\AnnotationCacheException`。任何失败都会在异常传播之前删除全部注解记录，因此之后的 `read()` 会失败，而不是返回不完整的数据。
- 当 `packages.ser` 缺失、其 reference 与包管理器不一致，或某条记录缺失或无效时，`read()` 抛出 `Exception\AnnotationCacheException`。它不向控制台写入任何内容。

## 生成的文件

注入的 `VendorDirInterface` 决定文件位置，自定义的 vendor 目录也是如此：

```text
<vendor>/.cache/
├── packages.ser
└── annotations/
    ├── super-kernel/metadata.ser
    ├── super-kernel/reflector.ser
    └── vendor/package.ser
```

`Constants\CacheLayout` 给出相对于 `<vendor>/.cache/` 的索引文件（`IndexPath`，`packages.ser`）和包记录目录（`PackageDirectory`，`annotations`）的名称。每个包文件保存一条经过校验的包记录（包括其诊断），最后发布的 `packages.ser` 把每个当前包名映射到其确切的、可为 null 的 `reference()` 值。还原时会加载注解的值类（包括枚举类），而被标注的应用类不会进入记录。这些文件是可信的本地运行时产物，不得由不受信任的写入方生成。

这些文件是由 `skernel build` 重写的派生数据；请随应用一起发布它们，`skernel package` 会这样做。包的 reference 用来确认记录属于已安装的包。反序列化或校验失败的记录会被拒绝而不是丢弃；再次运行 `skernel build` 即可替换它们。每个文件都通过同目录下的临时文件原子发布，但跨包的发布不是事务，也不提供进程锁。发布失败只删除 `.cache/packages.ser` 和 `.cache/annotations/`；`super-kernel/metadata` 的同级文件保持不变。

记录中的类名和对象布局必须与已安装的代码一致，schema 不兼容的记录会被拒绝。安装或更新包之后、修改注解或被标注的类之后、以及模型变更之后，请再次运行 `skernel build`；不要在不同版本的安装之间共享这些文件。Phar 必须携带为其所含代码构建的记录。

## 许可证

基于 MIT 许可证发布。参见 [LICENSE](LICENSE)。
