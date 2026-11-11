## ThinkPHP-PanGu - 盘古ThinkPHP开源索引!
面向 ThinkPHP 的开源工程索引：组件、框架、服务与发布工具，按能力组织，持续整理。
仓库地址：**<https://github.com/indiboy-create/ThinkPHP-PanGu/>**
在线站点：**<https://indiboy-create.github.io/ThinkPHP-PanGu/>**

# think-richtext

ThinkPHP 8 富文本入库转义与读取还原组件。使用 HTMLPurifier 严格白名单与 `htmlspecialchars`、`addslashes` 转义，对富文本写入数据库前进行过滤转义，读取后进行还原与二次过滤，防止数据库中出现可执行代码。

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
| `purifier.cache_path` | `''` | 定义缓存目录，留空使用系统临时目录 |

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

1. 还原已有 HTML 实体，保证重复写入幂等；
2. 移除 `<?php`、`<?=`、`<% %>` 等服务端可执行标签；
3. 使用 HTMLPurifier 执行严格 HTML 白名单净化，移除危险标签、事件属性、危险协议、危险 style 与注释；
4. 额外移除 `remove_tags` 配置的标签，清理无 `src` 的 iframe，恢复受信任的 base64 图片；
5. 按 `template_escape` 转义 `$`、`\`、`/`，防止写入模板或缓存文件后执行；
6. 执行 `htmlspecialchars` 转义，数据库保存 HTML 实体；
7. 按 `auto_slashes` 可选执行 `addslashes`。

读取 `decode`：

1. 按 `auto_slashes` 可选执行 `stripSlashes`；
2. 执行 `htmlspecialchars_decode` 还原 HTML；
3. 还原模板字符；
4. 再次执行 HTMLPurifier 白名单净化，防止历史脏数据或二次解码绕过。

关闭 `purifier.enabled` 时，第 3、4 步与读取第 4 步自动回退到内置 DOM 过滤逻辑。

## 安全建议

- ThinkPHP ORM 默认使用参数绑定，`auto_slashes` 保持 `false`，避免数据库出现多余反斜杠。
- 仅当使用原生 SQL 字符串拼接时开启 `auto_slashes`。
- 默认移除 `script`、`iframe`、`svg`、`object`、`embed`、`style` 等标签；需要嵌入视频时配置 `allowed_iframe_hosts`。
- 写入数据库的内容是 HTML 实体，直接输出不会执行；读取 `decode` 后已做二次过滤，可按需原始输出。
- 已内置 HTMLPurifier 严格白名单，可通过 `purifier.allowed` 自定义允许标签，通过 `purifier.css_allowed_properties` 控制 CSS 属性。

## 测试

```bash
composer test
```

或直接运行：

```bash
php tests/richtext_test.php
```