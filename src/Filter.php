<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText;

use Youvision\ThinkRichText\Exception\RichTextException;
use Youvision\ThinkRichText\Support\ConfigValidator;
use Youvision\ThinkRichText\Support\Reporter;

class Filter
{
    protected array $config = [];

    protected array $removeTags = [];

    protected bool $purifierInitialized = false;

    protected ?\HTMLPurifier $purifierInstance = null;

    protected bool $purifierDisabledByConfig = false;

    protected bool $purifierDegradationReported = false;

    public function __construct(array $config = [])
    {
        ConfigValidator::validate($config);

        $this->config     = $config;
        $this->removeTags = array_map(
            static fn ($tag) => strtolower(trim((string) $tag)),
            (array) ($config['remove_tags'] ?? [])
        );
    }

    public function clean(string $html): string
    {
        $this->assertInputLength($html);

        if (trim($html) === '') {
            return '';
        }

        $html = $this->removeServerCode($html);
        $purifier = $this->purifier();

        if ($purifier !== null) {
            $images = [];
            $html   = $this->protectDataImages($html, $images);

            try {
                $html = $purifier->purify($html);
                $html = $this->removeAdditionalTags($html);
                $html = $this->removeEmptyIframes($html);
                $html = $this->restoreDataImages($html, $images);
            } catch (\Throwable $e) {
                $html = $this->restoreDataImages($html, $images);
                $this->degrade('HTMLPurifier 净化失败：' . $e->getMessage(), '内置过滤', true);

                return class_exists(\DOMDocument::class)
                    ? $this->cleanWithDom($html)
                    : $this->fallbackClean($html);
            }

            return $html;
        }

        if (!$this->purifierDisabledByConfig && !$this->purifierDegradationReported) {
            $this->degrade('HTMLPurifier 不可用', '内置过滤', true);
        }

        if (!class_exists(\DOMDocument::class)) {
            $this->degrade('DOMDocument 扩展不可用', '正则兜底过滤', true);
            return $this->fallbackClean($html);
        }

        return $this->cleanWithDom($html);
    }

    protected function purifier(): ?\HTMLPurifier
    {
        if ($this->purifierInitialized) {
            return $this->purifierInstance;
        }

        $this->purifierInitialized = true;

        $settings = (array) ($this->config['purifier'] ?? []);
        $enabled  = array_key_exists('enabled', $settings) ? (bool) $settings['enabled'] : true;

        if (!$enabled) {
            $this->purifierDisabledByConfig = true;
            return null;
        }

        if (!class_exists(\HTMLPurifier::class) || !class_exists(\HTMLPurifier_Config::class)) {
            return null;
        }

        try {
            $purifierConfig = \HTMLPurifier_Config::createDefault();
            $purifierConfig->set('Core.Encoding', $this->configCharset());

            $doctype = (string) ($settings['doctype'] ?? 'HTML 4.01 Transitional');
            if ($doctype !== '') {
                $purifierConfig->set('HTML.Doctype', $doctype);
            }

            $purifierConfig->set('HTML.TidyLevel', (string) ($settings['tidy_level'] ?? 'none'));
            $purifierConfig->set('Attr.EnableID', (bool) ($settings['attr_allow_id'] ?? false));

            $schemes = [];

            foreach ($this->allowedProtocols() as $protocol) {
                if (in_array($protocol, ['javascript', 'vbscript', 'data', 'file'], true)) {
                    continue;
                }

                if (preg_match('/^[a-z][a-z0-9+.\-]*$/i', $protocol)) {
                    $schemes[$protocol] = true;
                }
            }

            if ($schemes !== []) {
                $purifierConfig->set('URI.AllowedSchemes', $schemes);
            }

            $allowed = $settings['allowed'] ?? null;
            if (is_array($allowed)) {
                $allowed = implode(',', $allowed);
            }
            if (is_string($allowed) && trim($allowed) !== '') {
                $purifierConfig->set('HTML.Allowed', trim($allowed));
            }

            $cssProperties = $settings['css_allowed_properties'] ?? null;
            if (is_string($cssProperties) && trim($cssProperties) !== '') {
                $cssProperties = array_filter(array_map('trim', explode(',', $cssProperties)));
            }
            if (is_array($cssProperties) && $cssProperties !== []) {
                $purifierConfig->set('CSS.AllowedProperties', $cssProperties);
            }

            $hosts = (array) ($this->config['allowed_iframe_hosts'] ?? []);
            if ($hosts !== []) {
                $purifierConfig->set('HTML.SafeIframe', true);
                $purifierConfig->set('URI.SafeIframeRegexp', $this->iframeRegexp($hosts));
            }

            $cachePath = $this->cachePath($settings['cache_path'] ?? null);
            if ($this->prepareCacheDirectory($cachePath)) {
                $purifierConfig->set('Cache.DefinitionImpl', 'Serializer');
                $purifierConfig->set('Cache.SerializerPath', $cachePath);
            } else {
                $this->degrade(
                    'HTMLPurifier 缓存目录不可用：' . $cachePath,
                    '不使用定义缓存',
                    false
                );
                $purifierConfig->set('Cache.DefinitionImpl', null);
            }

            $this->purifierInstance = new \HTMLPurifier($purifierConfig);
        } catch (\Throwable $e) {
            $this->purifierDegradationReported = true;
            $this->degrade('HTMLPurifier 初始化失败：' . $e->getMessage(), '内置过滤', true);
            $this->purifierInstance = null;
        }

        return $this->purifierInstance;
    }

    protected function cachePath(mixed $configured): string
    {
        $configured = trim((string) $configured);
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        if (Reporter::containerHas('app')) {
            try {
                $app = \think\Container::getInstance()->make('app');
                if (is_object($app) && method_exists($app, 'getRuntimePath')) {
                    $runtime = (string) $app->getRuntimePath();
                    if ($runtime !== '') {
                        return rtrim($runtime, '/\\') . DIRECTORY_SEPARATOR
                            . 'think-richtext' . DIRECTORY_SEPARATOR . 'htmlpurifier';
                    }
                }
            } catch (\Throwable $e) {
                // 未在 ThinkPHP 环境中运行时使用项目本地目录
            }
        }

        $base = getcwd() ?: dirname(__DIR__, 2);

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'think-richtext' . DIRECTORY_SEPARATOR . 'htmlpurifier';
    }

    protected function prepareCacheDirectory(string $path): bool
    {
        if ($path === '' || is_link($path)) {
            return false;
        }

        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            return false;
        }

        return is_writable($path);
    }

    protected function protectDataImages(string $html, array &$map): string
    {
        $map = [];

        if (!$this->boolConfig('allow_data_image', true)) {
            return $html;
        }

        $counter = 0;
        $token   = '__richtext_data_image_' . bin2hex(random_bytes(8)) . '_';
        $result  = preg_replace_callback(
            '#(<img\b[^>]*?\bsrc\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i',
            function (array $matches) use (&$map, &$counter, $token): string {
                $value = $matches[2] ?? $matches[3] ?? $matches[4] ?? '';

                if (!is_string($value) || !$this->isSafeDataImage($value)) {
                    return (string) $matches[0];
                }

                $placeholder = $token . (++$counter) . '__';
                $map[$placeholder] = $value;

                return $matches[1] . '"' . $placeholder . '"';
            },
            $html,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL
        );

        return $this->pregResult($result, $html, '保护 base64 图片');
    }

    protected function restoreDataImages(string $html, array $map): string
    {
        return $map === [] ? $html : str_replace(array_keys($map), array_values($map), $html);
    }

    protected function removeAdditionalTags(string $html): string
    {
        foreach ($this->removeTags as $tag) {
            if ($tag === '' || $tag === 'iframe') {
                continue;
            }

            $quoted = preg_quote($tag, '#');
            $html   = $this->pregResult(
                preg_replace('#<' . $quoted . '\b[^>]*>[\s\S]*?</' . $quoted . '\s*>#i', '', $html),
                $html,
                '移除标签内容'
            );
            $html   = $this->pregResult(
                preg_replace('#</?' . $quoted . '\b[^>]*>#i', '', $html),
                $html,
                '移除标签'
            );
        }

        return $html;
    }

    protected function removeEmptyIframes(string $html): string
    {
        if (stripos($html, '<iframe') === false) {
            return $html;
        }

        $result = preg_replace_callback(
            '#<iframe\b[^>]*>[\s\S]*?</iframe\s*>|<iframe\b[^>]*/?>#i',
            function (array $matches): string {
                $matched = preg_match('#\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $matches[0], $src);

                if ($matched === false) {
                    $this->degrade('解析 iframe src 失败：' . preg_last_error_msg(), '移除 iframe', false);
                    return '';
                }

                if ($matched !== 1) {
                    return '';
                }

                $value = $src[1] ?? $src[2] ?? $src[3] ?? '';

                return $this->isAllowedIframeUrl((string) $value) ? $matches[0] : '';
            },
            $html
        );

        return $this->pregResult($result, $html, '清理 iframe');
    }

    protected function iframeRegexp(array $hosts): string
    {
        $patterns = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));

            if ($host === '') {
                continue;
            }

            if (str_starts_with($host, '*.')) {
                $patterns[] = '(?:[a-z0-9-]+\.)*' . preg_quote(substr($host, 2), '%');
            } else {
                $patterns[] = preg_quote($host, '%');
            }
        }

        if ($patterns === []) {
            return '%^$%';
        }

        $schemes = array_values(array_intersect($this->allowedIframeSchemes(), ['http', 'https']));

        if ($schemes === []) {
            $schemes = ['http', 'https'];
        }

        return '%^(?:' . implode('|', $schemes) . ')://(?:' . implode('|', $patterns) . ')(?::\d+)?(?:/|$)%i';
    }

    protected function removeServerCode(string $html): string
    {
        $patterns = [
            '/<\?(?:php\b|=)?[\s\S]*?(?:\?>|$)/i',
            '/<%[\s\S]*?(?:%>|$)/',
            '/<script\b[^>]*\blanguage\s*=\s*("|\')?(?:php|asp|jsp)\1?[^>]*>[\s\S]*?<\/script\s*>/i',
        ];

        $result = preg_replace($patterns, '', $html);

        return $this->pregResult($result, $html, '移除服务端代码');
    }

    protected function cleanWithDom(string $html): string
    {
        $dom      = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $options  = LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;

        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div data-richtext-root="1">' . $html . '</div>',
            $options
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $this->fallbackClean($html);
        }

        $root = null;

        foreach ($dom->getElementsByTagName('div') as $element) {
            if ($element->getAttribute('data-richtext-root') === '1') {
                $root = $element;
                break;
            }
        }

        if ($root === null) {
            return $this->fallbackClean($html);
        }

        $this->cleanChildren($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return $output;
    }

    protected function cleanChildren(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }

            if ($child instanceof \DOMComment) {
                if ($this->boolConfig('remove_comments', true)) {
                    $node->removeChild($child);
                }
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'iframe') {
                if (!$this->isAllowedIframe($child)) {
                    $node->removeChild($child);
                    continue;
                }
            } elseif (in_array($tag, $this->removeTags, true)) {
                $node->removeChild($child);
                continue;
            }

            $this->cleanAttributes($child);
            $this->cleanChildren($child);
        }
    }

    protected function cleanAttributes(\DOMElement $element): void
    {
        $tag      = strtolower($element->nodeName);
        $remove   = [];
        $urlAttrs = $this->urlAttributes();

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name  = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if ($this->boolConfig('remove_event_attributes', true) && preg_match('/^on[a-z]+$/', $name)) {
                $remove[] = $attribute->nodeName;
                continue;
            }

            if ($name === 'srcdoc') {
                $remove[] = $attribute->nodeName;
                continue;
            }

            if ($name === 'style') {
                $style = $this->cleanStyle($value);
                if ($style === null) {
                    $remove[] = $attribute->nodeName;
                } else {
                    $element->setAttribute($attribute->nodeName, $style);
                }
                continue;
            }

            if ($name === 'srcset') {
                if (!$this->isSafeSrcset($value)) {
                    $remove[] = $attribute->nodeName;
                }
                continue;
            }

            if (in_array($name, $urlAttrs, true)) {
                if (!$this->isSafeUrlFor($tag, $name, $value)) {
                    $remove[] = $attribute->nodeName;
                }
            }
        }

        foreach (array_unique($remove) as $name) {
            $element->removeAttribute($name);
        }
    }

    protected function cleanStyle(string $style): ?string
    {
        if (trim($style) === '') {
            return null;
        }

        if (!$this->boolConfig('remove_dangerous_style', true)) {
            return $style;
        }

        $decoded = html_entity_decode($style, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $dangerous = preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import/i', $decoded);

        if ($dangerous === false) {
            $this->degrade('检查内联样式失败：' . preg_last_error_msg(), '移除样式', false);
            return null;
        }

        if ($dangerous === 1) {
            return null;
        }

        $hasUrl = preg_match('/url\s*\(/i', $decoded);

        if ($hasUrl === false) {
            $this->degrade('检查样式 url 失败：' . preg_last_error_msg(), '移除样式', false);
            return null;
        }

        if ($hasUrl === 1) {
            $safe = true;

            $checked = preg_replace_callback(
                '/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is',
                function (array $matches) use (&$safe): string {
                    if (!$this->isSafeUrl((string) $matches[2])) {
                        $safe = false;
                    }

                    return $matches[0];
                },
                $decoded
            );

            if ($checked === null) {
                $this->degrade('解析样式 url 失败：' . preg_last_error_msg(), '移除样式', false);
                return null;
            }

            if (!$safe) {
                return null;
            }
        }

        return $style;
    }

    protected function isSafeSrcset(string $srcset): bool
    {
        foreach (explode(',', $srcset) as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $item);

            if ($parts === false) {
                $this->degrade('解析 srcset 失败：' . preg_last_error_msg(), '移除 srcset', false);
                return false;
            }

            $url = $parts[0] ?? '';

            if (!$this->isSafeUrlFor('img', 'src', $url)) {
                return false;
            }
        }

        return true;
    }

    protected function isSafeUrlFor(string $tag, string $attribute, string $url): bool
    {
        $url = $this->normalizeUrl($url);

        if ($url === '') {
            return true;
        }

        if (stripos($url, 'data:') === 0) {
            return $tag === 'img' && $attribute === 'src' && $this->isSafeDataImage($url);
        }

        return $this->isSafeUrl($url);
    }

    protected function isSafeDataImage(string $url): bool
    {
        if (!$this->boolConfig('allow_data_image', true)) {
            return false;
        }

        $url = $this->normalizeUrl($url);

        $matched = preg_match(
            '#^data:image/(?:png|jpe?g|gif|webp|bmp|avif);base64,([a-z0-9+/=\s]+)$#i',
            $url,
            $matches
        );

        if ($matched !== 1) {
            return false;
        }

        $payload = preg_replace('/\s+/', '', (string) $matches[1]);

        if ($payload === null || $payload === '') {
            return false;
        }

        $max = (int) ($this->config['max_data_image_bytes'] ?? 0);

        if ($max > 0 && strlen($payload) > (int) ceil($max * 4 / 3) + 4) {
            $this->degrade('base64 图片超过大小限制，已移除', '移除图片', false);
            return false;
        }

        $binary = base64_decode($payload, true);

        if ($binary === false || $binary === '') {
            return false;
        }

        if ($max > 0 && strlen($binary) > $max) {
            $this->degrade('base64 图片超过大小限制，已移除', '移除图片', false);
            return false;
        }

        return true;
    }

    protected function isSafeUrl(string $url): bool
    {
        $url = $this->normalizeUrl($url);

        if ($url === '' || $url[0] === '#') {
            return true;
        }

        if (str_starts_with($url, '//')) {
            return true;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $matches)) {
            return in_array(strtolower($matches[1]), $this->allowedProtocols(), true);
        }

        return true;
    }

    protected function normalizeUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = trim($url);

        $url = $this->pregResult(
            preg_replace('/[\x00-\x20\x7F]+/', '', $url),
            $url,
            '规范化 URL 控制字符'
        );

        $url = $this->pregResult(
            preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $url),
            $url,
            '规范化 URL 零宽字符'
        );

        return str_replace('\\', '/', $url);
    }

    protected function isAllowedIframe(\DOMElement $element): bool
    {
        return $this->isAllowedIframeUrl((string) $element->getAttribute('src'));
    }

    protected function isAllowedIframeUrl(string $src): bool
    {
        $hosts = (array) ($this->config['allowed_iframe_hosts'] ?? []);

        if ($hosts === []) {
            return false;
        }

        $src = $this->normalizeUrl($src);

        if ($src === '' || !$this->isSafeUrl($src)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($src, PHP_URL_SCHEME));

        if ($scheme !== '' && !in_array($scheme, $this->allowedIframeSchemes(), true)) {
            return false;
        }

        $host = strtolower((string) parse_url($src, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach ($hosts as $allowed) {
            $allowed = strtolower(trim((string) $allowed));

            if ($allowed === '') {
                continue;
            }

            if ($allowed === $host) {
                return true;
            }

            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))) {
                return true;
            }
        }

        return false;
    }

    protected function allowedProtocols(): array
    {
        $protocols = array_map(
            static fn ($protocol) => strtolower(trim((string) $protocol)),
            (array) ($this->config['allowed_protocols'] ?? [])
        );

        return array_values(array_filter($protocols));
    }

    protected function allowedIframeSchemes(): array
    {
        $schemes = array_map(
            static fn ($scheme) => strtolower(trim((string) $scheme)),
            (array) ($this->config['allowed_iframe_schemes'] ?? ['http', 'https'])
        );

        return array_values(array_filter($schemes));
    }

    protected function urlAttributes(): array
    {
        return [
            'href',
            'src',
            'action',
            'formaction',
            'poster',
            'background',
            'cite',
            'longdesc',
            'dynsrc',
            'lowsrc',
            'xlink:href',
        ];
    }

    protected function fallbackClean(string $html): string
    {
        $tags = array_map(
            static fn ($tag) => preg_quote((string) $tag, '#'),
            $this->removeTags
        );

        if ($tags !== []) {
            $alt  = implode('|', $tags);
            $html = $this->pregResult(
                preg_replace('#<(' . $alt . ')\b[^>]*>[\s\S]*?</\1\s*>#i', '', $html),
                $html,
                '移除危险标签内容'
            );
            $html = $this->pregResult(
                preg_replace('#</?(?:' . $alt . ')\b[^>]*>#i', '', $html),
                $html,
                '移除危险标签'
            );
        }

        $html = $this->pregResult(
            preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html),
            $html,
            '移除事件属性'
        );

        $attributes = array_map(
            static fn (string $attribute): string => preg_quote($attribute, '#'),
            $this->urlAttributes()
        );

        $html = $this->pregResult(
            preg_replace(
                '#\s+(?:' . implode('|', $attributes) . ')\s*=\s*(?:"[^"]*(?:javascript|vbscript|data)\s*:[^"]*"|\'[^\']*(?:javascript|vbscript|data)\s*:[^\']*\'|[^\s>]*(?:javascript|vbscript|data)\s*:[^\s>]*)/i',
                '',
                $html
            ),
            $html,
            '移除危险协议'
        );

        return $html;
    }

    protected function configCharset(): string
    {
        $charset = strtoupper(trim((string) ($this->config['charset'] ?? 'UTF-8')));

        return $charset === '' ? 'UTF-8' : $charset;
    }

    protected function assertInputLength(string $html): void
    {
        $max = (int) ($this->config['max_input_length'] ?? 0);

        if ($max > 0 && strlen($html) > $max) {
            throw new RichTextException(
                sprintf('富文本内容超过最大长度限制：%d 字节（上限 %d 字节）', strlen($html), $max)
            );
        }
    }

    protected function degrade(string $reason, string $fallback, bool $throwInStrict): void
    {
        $message = $reason . '，已回退到' . $fallback;

        if ($throwInStrict && $this->boolConfig('strict_mode', false)) {
            throw new RichTextException($message);
        }

        Reporter::warning($message);
    }

    protected function pregResult(?string $result, string $fallback, string $context): string
    {
        if ($result !== null) {
            return $result;
        }

        $this->degrade(
            $context . '失败：' . preg_last_error_msg(),
            '保留原内容',
            false
        );

        return $fallback;
    }

    protected function boolConfig(string $name, bool $default): bool
    {
        return array_key_exists($name, $this->config) ? (bool) $this->config[$name] : $default;
    }
}