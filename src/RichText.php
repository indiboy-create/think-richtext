<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText;

class RichText
{
    protected array $config = [];

    protected Filter $htmlFilter;

    public function __construct(array $config = [])
    {
        $defaultFile = dirname(__DIR__) . '/config/richtext.php';
        $default     = is_file($defaultFile) ? (array) include $defaultFile : [];

        if ($config === [] && class_exists(\think\facade\Config::class)) {
            try {
                $config = (array) \think\facade\Config::get('richtext', []);
            } catch (\Throwable $e) {
                $config = [];
            }
        }

        $this->config     = array_merge($default, $config);
        $this->htmlFilter = new Filter($this->config);
    }

    /**
     * 写入数据库前的统一处理。
     * 对应 connect.php 的 RepPostStr + dgdb_repzy + addslashes。
     */
    public function encode(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = $this->unescape($value);

        if ($this->boolConfig('filter_html', true)) {
            $value = $this->htmlFilter->clean($value);
        }

        $value = $this->escapeTemplateChars($value);
        $value = $this->escape($value);

        if ($this->boolConfig('auto_slashes', false)) {
            $level = strtolower((string) $this->config['slash_mode']) === 'double' ? 2 : 1;
            $value = $this->addSlashes($value, $level);
        }

        return $value;
    }

    /**
     * 从数据库读取后的统一处理。
     * 对应 connect.php 的 dgdbe_rpstr_re + stripSlashes。
     */
    public function decode(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($this->boolConfig('auto_slashes', false)) {
            $level = strtolower((string) $this->config['slash_mode']) === 'double' ? 2 : 1;
            $value = $this->stripSlashes($value, $level);
        }

        $value = $this->unescape($value);
        $value = $this->restoreTemplateChars($value);

        if ($this->boolConfig('filter_html', true)) {
            $value = $this->htmlFilter->clean($value);
        }

        return $value;
    }

    /**
     * htmlspecialchars 转义，对应 ehtmlspecialchars。
     */
    public function escape(?string $value, ?int $flags = null): string
    {
        return htmlspecialchars((string) $value, $flags ?? $this->flags(), $this->charset());
    }

    /**
     * htmlspecialchars_decode 还原，对应 dgdbe_rpstr_re 中的解码步骤。
     */
    public function unescape(?string $value, ?int $flags = null): string
    {
        return htmlspecialchars_decode((string) $value, $flags ?? $this->flags());
    }

    /**
     * addslashes 处理，对应 eaddslashes / eaddslashes2。
     */
    public function addSlashes(?string $value, int $level = 1): string
    {
        $value = (string) $value;

        if ($level <= 0) {
            return $value;
        }

        if ($this->boolConfig('magic_quotes', false)) {
            return $level >= 2 ? addslashes($value) : $value;
        }

        for ($i = 0; $i < $level; $i++) {
            $value = addslashes($value);
        }

        return $value;
    }

    /**
     * stripSlashes 处理，对应 estripSlashes / estripSlashes2。
     */
    public function stripSlashes(?string $value, int $level = 1): string
    {
        $value = (string) $value;

        if ($level <= 0) {
            return $value;
        }

        if ($this->boolConfig('magic_quotes', false)) {
            $value = stripslashes($value);
            $level--;
        }

        for ($i = 0; $i < $level; $i++) {
            $value = stripslashes($value);
        }

        return $value;
    }

    /**
     * 仅执行安全过滤，不做 htmlspecialchars 转义。
     */
    public function filter(?string $value): string
    {
        return $this->htmlFilter->clean((string) $value);
    }

    /**
     * 提取纯文本摘要，对应 SubSmalltextVal。
     */
    public function plain(?string $html, ?int $length = null): string
    {
        $html = $this->decode($html);
        $html = str_replace(
            ["\r\n", '<br />', '<br>', '<br/>', '&nbsp;', "\xC2\xA0"],
            ['', "\r\n", "\r\n", "\r\n", ' ', ' '],
            $html
        );

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, $this->charset());
        $text = trim($text);

        if ($length !== null) {
            if ($length <= 0) {
                return '';
            }

            $text = mb_substr($text, 0, $length, $this->charset());
        }

        return $text;
    }

    public function summary(?string $html, ?int $length = null): string
    {
        return $this->plain($html, $length);
    }

    /**
     * 批量处理写入字段。
     */
    public function encodeFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $this->encode($data[$field]);
            }
        }

        return $data;
    }

    /**
     * 批量处理读取字段。
     */
    public function decodeFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $this->decode($data[$field]);
            }
        }

        return $data;
    }

    public function getConfig(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->config;
        }

        return array_key_exists($name, $this->config) ? $this->config[$name] : $default;
    }

    public function getCharset(): string
    {
        return $this->charset();
    }

    public static function config(string $name, mixed $default = null): mixed
    {
        $configFile = dirname(__DIR__) . '/config/richtext.php';
        $config     = is_file($configFile) ? (array) include $configFile : [];

        if (class_exists(\think\facade\Config::class)) {
            try {
                $current = \think\facade\Config::get('richtext');
                if (is_array($current)) {
                    $config = array_merge($config, $current);
                }
            } catch (\Throwable $e) {
                // 未初始化 ThinkPHP 时使用默认配置
            }
        }

        return array_key_exists($name, $config) ? $config[$name] : $default;
    }

    protected function escapeTemplateChars(string $value): string
    {
        $mode = strtolower((string) $this->config['template_escape']);

        if ($mode === 'none') {
            return $value;
        }

        $value = str_replace('$', '&#36;', $value);
        $value = str_replace('\\', '&#92;', $value);

        if ($mode === 'full') {
            $value = str_replace('/', '&#47;', $value);
        }

        return $value;
    }

    protected function restoreTemplateChars(string $value): string
    {
        $mode = strtolower((string) $this->config['template_escape']);

        if ($mode === 'none') {
            return $value;
        }

        $value = str_replace('&#36;', '$', $value);
        $value = str_replace('&#92;', '\\', $value);

        if ($mode === 'full') {
            $value = str_replace('&#47;', '/', $value);
        }

        return $value;
    }

    protected function flags(): int
    {
        $flags = $this->config['escape_flags'] ?? null;

        return $flags === null ? ENT_QUOTES | ENT_SUBSTITUTE : (int) $flags;
    }

    protected function charset(): string
    {
        $charset = (string) ($this->config['charset'] ?? 'UTF-8');

        return $charset === '' ? 'UTF-8' : $charset;
    }

    protected function boolConfig(string $name, bool $default): bool
    {
        return array_key_exists($name, $this->config) ? (bool) $this->config[$name] : $default;
    }
}