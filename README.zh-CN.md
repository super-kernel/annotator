<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Annotator

`super-kernel/annotator` 恢复 `skernel build` 从项目及其已安装的包中收集的原生 PHP 注解，并在不扫描源码、不加载被注解类的情况下回答关于它们的查询。

## 安装

```bash
composer require super-kernel/annotator
```

本包除 PHP 外没有其他要求。它恢复的记录只有在项目中运行过 `skernel build` 之后才存在。`super-kernel/di` 在创建根容器时恢复这些记录；不使用容器时，由 `super-kernel/metadata` 的提供者提供恢复所需的内容。

## 提供的能力

- 一个不可变的收集器，按注解的确切类或按其父类、接口查找带有该注解的类和方法。
- 一个类、方法或属性上声明的注解，以在构建时已构造并校验过的实例形式提供。
- 构建的跳过类诊断：`skernel build` 无法收集其注解的那些类。
- 恢复 `skernel build` 生成的记录，并拒绝与已安装的包不一致的记录。
- 一个供构建工具写出记录的发布器。

它不会：

- 扫描类文件、从源码构造注解或决定收集哪些类，这由 `skernel build` 负责；
- 读取 DocBlock 注释注解；它只支持原生 PHP 注解；
- 在回答查询时加载或反射被注解的类；
- 在应用启动时打印跳过类诊断。

## 快速开始

在由 `super-kernel/skeleton` 创建的应用中，声明一个用于路由的注解。将它保存为 `src/Routing/Route.php`：

```php
<?php

declare(strict_types=1);

namespace Src\Routing;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    public function __construct(public string $method, public string $path)
    {
    }
}
```

在控制器上使用它。将控制器保存为 `src/Http/PageController.php`：

```php
<?php

declare(strict_types=1);

namespace Src\Http;

use Src\Routing\Route;

final class PageController
{
    #[Route('GET', '/')]
    public function home(): string
    {
        return 'Home';
    }

    #[Route('GET', '/about')]
    #[Route('GET', '/about-us')]
    public function about(): string
    {
        return 'About';
    }
}
```

向收集器查询所有带路由的方法。将脚本保存为 `bin/routes.php`：

```php
<?php

declare(strict_types=1);

use Src\Routing\Route;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\AnnotationCollectorInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$collector = ApplicationContext::getContainer()->get(AnnotationCollectorInterface::class);

foreach ($collector->getMethodsWithAttribute(Route::class) as $class => $methods) {
    foreach ($methods as $method => $routes) {
        foreach ($routes as $route) {
            echo $route->attribute()->method, ' ', $route->attribute()->path, ' -> ', $class, '::', $method, PHP_EOL;
        }
    }
}
```

构建项目并运行脚本：

```bash
skernel build
php bin/routes.php
```

它会打印：

```text
GET /about -> Src\Http\PageController::about
GET /about-us -> Src\Http\PageController::about
GET / -> Src\Http\PageController::home
```

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\Contract\AnnotationCollectorInterface` | 接口 | 查询收集到的注解；由 SuperKernel 容器提供。 |
| `SuperKernel\Contract\AttributeMetadataInterface` | 接口 | 一个收集到的注解：`attribute()` 返回实例，`types()` 返回它的类以及捕获到的父类型。 |
| `SuperKernel\Contract\PackageAnnotationsInterface` | 接口 | 一个包的类、方法和属性注解。 |
| `SuperKernel\Contract\ScanDiagnosticsInterface` | 接口 | 返回收集器或包的跳过类诊断。 |
| `SuperKernel\Annotator\AnnotationCollector` | 类 | 收集器；`new AnnotationCollector($packages)` 用包记录创建一个收集器，可用于测试。 |
| `SuperKernel\Annotator\Cache\AnnotationCache` | 类 | `read()` 从 `skernel build` 的记录恢复收集器。 |
| `SuperKernel\Annotator\Cache\AnnotationPublisher` | 类 | `publish()` 写出记录；由构建工具调用。 |
| `SuperKernel\Annotator\Model\AttributeMetadata` | 类 | 构建工具创建并发布的、收集到的注解。 |
| `SuperKernel\Annotator\Model\PackageAnnotations` | 类 | 构建工具创建并发布的、一个包的记录。 |

## 需要了解的行为

- 在 `composer install` 或 `composer update` 之后，以及添加或修改注解或带注解的类之后，请运行 `skernel build`，或者用会先构建的 `skernel run` 启动。修改带注解的类不会被检测到；查询会根据上一次构建作答。
- 当某条记录缺失或无效，或者记录属于其他版本的包时，`read()` 抛出 `SuperKernel\Annotator\Exception\AnnotationCacheException`，例如 `The annotation package-reference stub does not match current Composer metadata.`。容器把它报告为 `SuperKernel\Metadata\Exception\BuildRequiredException`，消息为 ``The generated annotation records cannot be restored. Run `skernel build` to generate the project metadata.``
- 查询只返回在被查询的类或成员上声明的注解；继承来的方法和属性列在声明它们的类之下。
- 一个类的方法按名称顺序列出；同一个类或成员上的注解保持其声明顺序。
- `skernel build` 无法收集的类不会出现在任何查询中；它出现在跳过类诊断里。

## 文档

开发者文档用完整示例介绍每一种查询、诊断、不使用容器的恢复、发布记录以及生成的文件：[SuperKernel Annotator](https://github.com/super-kernel/.github/blob/main/docs/components/annotator.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
