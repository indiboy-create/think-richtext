<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText;

class Filter
{
    protected array $config = [];

    protected array $removeTags = [];

    protected bool $purifierInitialized = false;

    protected ?\HTMLPurifier $purifierInstance = null;

    public function __construct(array $config = [])
    {
        $this->config     = $config;
        $this->removeTags = array_map(
            static fn ($tag) => strtolower(trim((string) $tag)),
            (array) ($config['remove_tags'] ?? [])
        );
    }

    public function clean(string $html): string
    {
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

                return class_exists(\DOMDocument::class)
                    ? $this->cleanWithDom($html)
                    : $this->fallbackClean($html);
            }

            return $html;
        }

        if (!class_exists(\DOMDocument::class)) {
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

        if (!$enabled || !class_exists(\HTMLPurifier::class) || !class_exists(\HTMLPurifier_Config::class)) {
            return null;
        }

        try {
            $purifierConfig = \HTMLPurifier_Config::createDefault();
            $purifierConfig->set('Core.Encoding', (string) ($this->config['charset'] ?? 'UTF-8'));

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

            $cachePath = trim((string) ($settings['cache_path'] ?? ''));
            if ($cachePath === '') {
                $cachePath = sys_get_temp_dir() . '/think-richtext-htmlpurifier';
            }

            if (is_dir($cachePath) || @mkdir($cachePath, 0777, true) || is_dir($cachePath)) {
                $purifierConfig->set('Cache.DefinitionImpl', 'Serializer');
                $purifierConfig->set('Cache.SerializerPath', $cachePath);
            } else {
                $purifierConfig->set('Cache.DefinitionImpl', null);
            }

            $this->purifierInstance = new \HTMLPurifier($purifierConfig);
        } catch (\Throwable $e) {
            $this->purifierInstance = null;
        }

        return $this->purifierInstance;
    }

    protected function protectDataImages(string $html, array &$map): string
    {
        $map = [];

        if (!$this->boolConfig('allow_data_image', true)) {
            return $html;
        }

        $counter = 0;
        $result  = preg_replace_callback(
            '#(<img\b[^>]*?\bsrc\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i',
            function (array $matches) use (&$map, &$counter): string {
                $value = $matches[2] ?? $matches[3] ?? $matches[4] ?? '';

                if (!is_string($value) || !$this->isSafeDataImage($value)) {
                    return (string) $matches[0];
                }

                $placeholder = 'https://think-richtext.invalid/data-image-' . (++$counter) . '.png';
                $map[$placeholder] = $value;

                return $matches[1] . '"' . $placeholder . '"';
            },
            $html,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL
        );

        return $result ?? $html;
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
            $html   = preg_replace('#<' . $quoted . '\b[^>]*>[\s\S]*?</' . $quoted . '\s*>#i', '', $html) ?? $html;
            $html   = preg_replace('#</?' . $quoted . '\b[^>]*>#i', '', $html) ?? $html;
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
            static function (array $matches): string {
                return preg_match('#\bsrc\s*=\s*(["\']?)\s*https?://#i', $matches[0]) ? $matches[0] : '';
            },
            $html
        );

        return $result ?? $html;
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

        return $result ?? '';
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

        if (preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import/i', $decoded)) {
            return null;
        }

        if (preg_match('/url\s*\(/i', $decoded)) {
            $safe = true;

            preg_replace_callback(
                '/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is',
                function (array $matches) use (&$safe): string {
                    if (!$this->isSafeUrl((string) $matches[2])) {
                        $safe = false;
                    }

                    return $matches[0];
                },
                $decoded
            );

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
            $url   = $parts[0] ?? '';

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

        return (bool) preg_match(
            '#^data:image/(?:png|jpe?g|gif|webp|bmp|avif);base64,[a-z0-9+/=\s]+$#i',
            $url
        );
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

        $cleaned = preg_replace('/[\x00-\x20\x7F]+/', '', $url);
        $url     = $cleaned === null ? $url : $cleaned;

        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $url);
        $url     = $cleaned === null ? $url : $cleaned;

        return str_replace('\\', '/', $url);
    }

    protected function isAllowedIframe(\DOMElement $element): bool
    {
        $hosts = (array) ($this->config['allowed_iframe_hosts'] ?? []);

        if ($hosts === []) {
            return false;
        }

        $src = $this->normalizeUrl((string) $element->getAttribute('src'));

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
            $html = preg_replace('#<(' . $alt . ')\b[^>]*>[\s\S]*?</\1\s*>#i', '', $html) ?? $html;
            $html = preg_replace('#</?(?:' . $alt . ')\b[^>]*>#i', '', $html) ?? $html;
        }

        $html = preg_replace(
            '/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '',
            $html
        ) ?? $html;

        $html = preg_replace(
            '/\s+(?:href|src|action|formaction|poster|background|cite|longdesc|dynsrc|lowsrc)\s*=\s*(?:"[^"]*(?:javascript|vbscript|data)\s*:[^"]*"|\'[^\']*(?:javascript|vbscript|data)\s*:[^\']*\'|[^\s>]*(?:javascript|vbscript|data)\s*:[^\s>]*)/i',
            '',
            $html
        ) ?? $html;

        return $html;
    }

    protected function boolConfig(string $name, bool $default): bool
    {
        return array_key_exists($name, $this->config) ? (bool) $this->config[$name] : $default;
    }
}