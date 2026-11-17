<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Support;

use Youvision\ThinkRichText\Exception\RichTextException;

final class ConfigValidator
{
    public static function validate(array $config): void
    {
        self::enum($config, 'slash_mode', ['normal', 'double']);
        self::enum($config, 'template_escape', ['none', 'basic', 'full']);
        self::nonNegativeInt($config, 'max_input_length');
        self::nonNegativeInt($config, 'max_data_image_bytes');

        foreach (['strict_mode', 'auto_slashes', 'magic_quotes', 'filter_html',
            'remove_event_attributes', 'remove_dangerous_style', 'allow_data_image', 'remove_comments',
            'decode_on_get'] as $key) {
            self::boolish($config, $key);
        }

        if (array_key_exists('charset', $config)) {
            if (!is_string($config['charset']) || trim($config['charset']) === '') {
                throw new RichTextException('配置项 charset 必须是非空字符串');
            }
        }

        if (array_key_exists('escape_flags', $config) && !is_int($config['escape_flags'])) {
            throw new RichTextException('配置项 escape_flags 必须是整数');
        }

        foreach (['remove_tags', 'allowed_protocols', 'allowed_iframe_hosts',
            'allowed_iframe_schemes', 'fields'] as $key) {
            self::stringList($config, $key);
        }

        if (array_key_exists('purifier', $config)) {
            self::purifier($config['purifier']);
        }
    }

    private static function purifier(mixed $purifier): void
    {
        if (!is_array($purifier)) {
            throw new RichTextException('配置项 purifier 必须是数组');
        }

        self::boolish($purifier, 'enabled', 'purifier.');
        self::boolish($purifier, 'attr_allow_id', 'purifier.');
        self::enum($purifier, 'tidy_level', ['none', 'light', 'medium', 'heavy'], 'purifier.');

        foreach (['doctype', 'cache_path'] as $key) {
            if (array_key_exists($key, $purifier) && !is_string($purifier[$key])) {
                throw new RichTextException('配置项 purifier.' . $key . ' 必须是字符串');
            }
        }

        foreach (['allowed', 'css_allowed_properties'] as $key) {
            if (!array_key_exists($key, $purifier) || $purifier[$key] === null) {
                continue;
            }

            if (is_string($purifier[$key])) {
                continue;
            }

            self::stringList($purifier, $key, 'purifier.');
        }
    }

    private static function enum(array $config, string $key, array $allowed, string $prefix = ''): void
    {
        if (!array_key_exists($key, $config)) {
            return;
        }

        $value = strtolower((string) $config[$key]);

        if (!in_array($value, $allowed, true)) {
            throw new RichTextException(sprintf(
                '配置项 %s 的值 "%s" 无效，允许值：%s',
                $prefix . $key,
                (string) $config[$key],
                implode(', ', $allowed)
            ));
        }
    }

    private static function isBoolish(mixed $candidate): bool
    {
        return is_bool($candidate) || in_array($candidate, [0, 1, '0', '1'], true);
    }

    private static function boolish(array $config, string $key, string $prefix = ''): void
    {
        if (array_key_exists($key, $config) && !self::isBoolish($config[$key])) {
            throw new RichTextException('配置项 ' . $prefix . $key . ' 必须是布尔值或 0/1');
        }
    }

    private static function nonNegativeInt(array $config, string $key): void
    {
        if (!array_key_exists($key, $config)) {
            return;
        }

        $value = $config[$key];

        if (!is_int($value) || $value < 0) {
            throw new RichTextException('配置项 ' . $key . ' 必须是非负整数');
        }
    }

    private static function stringList(array $config, string $key, string $prefix = ''): void
    {
        if (!array_key_exists($key, $config)) {
            return;
        }

        $value = $config[$key];

        if (!is_array($value)) {
            throw new RichTextException('配置项 ' . $prefix . $key . ' 必须是数组');
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new RichTextException('配置项 ' . $prefix . $key . ' 的每一项都必须是字符串');
            }
        }
    }
}
