# think-richtext

ThinkPHP 8 富文本入库转义与读取还原组件。基于 HTMLPurifier 白名单和 `htmlspecialchars` / `addslashes` 转义，在写入前过滤危险 HTML，在读取后还原并二次过滤，降低数据库中出现可执行代码的风险。

## 兼容性与依赖

- PHP >= 8.0，需启用 `dom`、`libxml`、`mbstring` 扩展。
- `ezyang/htmlpurifier` 为运行时依赖；`topthink/framework` ^8.0 为可选的开发/运行环境依赖。
- 未安装 ThinkPHP 时，助手函数和 `RichText` 类可独立使用。

## 安装

```bash
composer require youvision/think-richtext
```

安装时会自动拉取 `ezyang/htmlpurifier`。要求 PHP >= 8.0，并启用 `ext-dom`、`ext-libxml`、`ext-mbstring`。

## 配置

配置文件位于包内 `config/richtext.php`。Service 注册时会自动读取并合并项目 `config/richtext.php` 中的同名配置，按需覆盖即可。核心配置：

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `charset` | `UTF-8` | 转义与截取使用的编码 |
| `escape_flags` | `ENT_QUOTES \| ENT_SUBSTITUTE` | htmlspecialchars 转义标志 |
| `strict_mode` | `false` | 意外安全降级时抛异常；关闭时仅记录日志 |
| `max_input_length` | `2097152` | 单次处理最大字节数，`0` 表示不限制，超限抛异常 |
| `max_data_image_bytes` | `524288` | 单个 base64 图片解码后最大字节数，`0` 表示不限制 |
| `auto_slashes` | `false` | 是否自动 addslashes，ORM 参数绑定时保持关闭 |
| `slash_mode` | `normal` | `normal` 单次、`double` 双层转义 |
| `magic_quotes` | `false` | 兼容旧 magic_quotes_gpc 数据 |
| `template_escape` | `basic` | `none` / `basic`（`$`、`\`）/ `full`（额外处理 `/`） |
| `filter_html` | `true` | 是否执行 HTML 安全过滤 |
| `remove_tags` | 危险标签列表 | 整段移除的标签，整体覆盖默认值 |
| `remove_event_attributes` | `true` | 移除所有 `on*` 事件属性 |
| `remove_dangerous_style` | `true` | 移除含 `expression()`、`javascript:` 等危险样式 |
| `allowed_protocols` | `http`、`https`、`mailto`、`tel`、`ftp` | 允许的 URL 协议 |
| `allow_data_image` | `true` | 允许 `data:image/png` 等位图，始终拒绝 SVG |
| `allowed_iframe_hosts` | `[]` | iframe 域名白名单，为空时移除全部 iframe |
| `allowed_iframe_schemes` | `http`、`https` | iframe 允许的协议 |
| `remove_comments` | `true` | 移除 HTML 注释 |
| `decode_on_get` | `true` | 模型 trait 读取时自动 decode |
| `fields` | `[]` | 全局富文本字段名 |

### HTMLPurifier 配置

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `purifier.enabled` | `true` | 是否启用 HTMLPurifier，关闭后回退内置 DOM 过滤 |
| `purifier.doctype` | `HTML 4.01 Transitional` | HTML 文档类型，决定标签与属性定义 |
| `purifier.tidy_level` | `none` | 标签整理级别：`none`、`light`、`medium`、`heavy` |
| `purifier.allowed` | `null` | 自定义 HTML 白名单，设置后完整覆盖默认白名单 |
| `purifier.css_allowed_properties` | `null` | 自定义允许的 CSS 属性，设置后完整覆盖默认值 |
| `purifier.attr_allow_id` | `false` | 是否允许 `id` 属性 |
| `purifier.cache_path` | `''` | 定义缓存目录，留空时优先使用 ThinkPHP runtime 目录，其次当前工作目录的 `runtime/` |

## 写入与读取

### 门面 / 助手函数

```php
use Youvision\ThinkRichText\Facade\RichText;

$content = RichText::encode($request->post('content'));   // 写库前
$article = Article::create(['content' => $content]);

$html = RichText::decode($article->content);              // 读库后
```

```php
$content = richtext_encode($request->post('content'));
$html    = richtext_decode($article->content);
$text    = richtext_plain($article->content, 120);

// 已调用 decode 或来自可信内存内容时，可跳过重复过滤
$text    = richtext_plain($html, 120, true);
```

### 模型 trait

```php
use think\Model;
use Youvision\ThinkRichText\Model\Concern\HasRichText;

class Article extends Model
{
    use HasRichText;

    protected $richTextFields = ['content', 'intro'];
}
```

写入时自动执行 `encode`，读取时自动执行 `decode`。读取数据库原始值：

```php
$raw = $article->getRawRichText('content');
```

模板中输出已还原的 HTML 时不要再次执行 `htmlspecialchars`：

```html
{:$article['content']}
```

### 批量处理

```php
$saved  = RichText::encodeFields($data, ['content', 'intro']);
$loaded = RichText::decodeFields($row, ['content', 'intro']);
```

## 安全处理流程

写入 `encode`：

1. 检查输入字节数，超过 `max_input_length` 时抛出 `RichTextException`；
2. 还原已有 HTML 实体，保证重复写入幂等；
3. 移除 `<?php`、`<?=`、`<% %>` 等服务端可执行标签；
4. 使用 HTMLPurifier 执行白名单净化，移除危险标签、事件属性、危险协议、危险 style 与注释；
5. 额外移除 `remove_tags` 配置的标签，清理非白名单 iframe，恢复大小合规的 base64 图片；
6. 按 `template_escape` 转义 `$`、`\`、`/`，防止写入模板或缓存文件后执行；
7. 执行 `htmlspecialchars` 转义，数据库保存 HTML 实体；
8. 按 `auto_slashes` 可选执行 `addslashes`。

读取 `decode`：

1. 检查输入字节数；
2. 按 `auto_slashes` 可选执行 `stripSlashes`；
3. 执行 `htmlspecialchars_decode` 还原 HTML；
4. 还原模板字符；
5. 再次执行 HTMLPurifier 白名单净化，防止历史脏数据或二次解码绕过。

配置在构造时统一校验，非法枚举值、负数长度限制、非法列表类型等会立即抛出 `RichTextException`，不再静默按默认分支处理。

## 安全边界与故障模式

- 本组件只处理 **HTML 字符串过滤与转义**，不负责文件上传校验、MIME 检测、访问控制、CSRF 防护或数据库参数绑定。
- 净化强度按路径区分：HTMLPurifier 为白名单；内置 DOM 过滤为黑名单；正则兜底最弱，仅在 `DOMDocument` 不可用时启用。
- 显式设置 `purifier.enabled = false` 属于预期配置，会走内置 DOM 过滤且不会报警。
- HTMLPurifier 初始化/净化失败、DOM 扩展缺失属于意外降级：默认记录日志；`strict_mode = true` 时直接抛异常。
- HTMLPurifier 缓存目录默认使用 ThinkPHP 应用 runtime 目录，以 `0750` 权限创建；缓存失败只记录日志并退化为无缓存净化。
- `plain()` / `summary()` 默认会重新解码并过滤；传入第三个参数 `true` 可跳过，但仅适用于内容已被本组件处理过或完全可信的场景。

## 安全建议

- ThinkPHP ORM 默认使用参数绑定，`auto_slashes` 保持 `false`，避免数据库出现多余反斜杠。
- 仅当使用原生 SQL 字符串拼接时开启 `auto_slashes`。
- 生产环境建议开启 `strict_mode`，并显式配置 `purifier.cache_path` 到应用私有可写目录。
- 根据业务正文大小调整 `max_input_length` 和 `max_data_image_bytes`，避免超大内容消耗内存。
- 写入数据库的内容是 HTML 实体，直接输出不会执行；读取 `decode` 后已做二次过滤，可按需原始输出。
- 可通过 `purifier.allowed` 自定义允许标签，通过 `purifier.css_allowed_properties` 控制 CSS 属性；自定义白名单会完整覆盖默认白名单。

## 测试

```bash
composer test
```

该命令会依次运行原有脚本回归测试与 PHPUnit 集成测试。也可单独运行：

```bash
composer test:legacy
composer test:unit
```

CI 会在 PHP 8.0 至 8.4 矩阵中执行同一套测试。