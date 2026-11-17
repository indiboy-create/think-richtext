<?php

use Youvision\ThinkRichText\RichText;

if (!function_exists('richtext_instance')) {
    function richtext_instance(): RichText
    {
        if (\Youvision\ThinkRichText\Support\Reporter::containerHas('richtext')) {
            try {
                $instance = \think\Container::getInstance()->make('richtext');
                if ($instance instanceof RichText) {
                    return $instance;
                }
            } catch (\Throwable $e) {
                \Youvision\ThinkRichText\Support\Reporter::warning(
                    '从容器获取 richtext 服务失败：' . $e->getMessage()
                );
            }
        }

        return new RichText();
    }
}

if (!function_exists('richtext_encode')) {
    function richtext_encode($value): string
    {
        return richtext_instance()->encode($value === null ? null : (string) $value);
    }
}

if (!function_exists('richtext_decode')) {
    function richtext_decode($value): string
    {
        return richtext_instance()->decode($value === null ? null : (string) $value);
    }
}

if (!function_exists('richtext_filter')) {
    function richtext_filter($value): string
    {
        return richtext_instance()->filter($value === null ? null : (string) $value);
    }
}

if (!function_exists('richtext_plain')) {
    function richtext_plain($value, $length = null, $alreadyDecoded = false): string
    {
        return richtext_instance()->plain(
            $value === null ? null : (string) $value,
            $length === null ? null : (int) $length,
            (bool) $alreadyDecoded
        );
    }
}

if (!function_exists('richtext_escape')) {
    function richtext_escape($value, $flags = null): string
    {
        return richtext_instance()->escape(
            $value === null ? null : (string) $value,
            $flags === null ? null : (int) $flags
        );
    }
}

if (!function_exists('richtext_unescape')) {
    function richtext_unescape($value, $flags = null): string
    {
        return richtext_instance()->unescape(
            $value === null ? null : (string) $value,
            $flags === null ? null : (int) $flags
        );
    }
}

/*
 * 以下函数用于兼容 connect.php 中的旧调用，方便存量代码平滑迁移。
 * 同名函数已存在时不会重复定义。
 */
if (!function_exists('ehtmlspecialchars')) {
    function ehtmlspecialchars($value, $flags = ENT_COMPAT)
    {
        return richtext_instance()->escape($value === null ? null : (string) $value, (int) $flags);
    }
}

if (!function_exists('eaddslashes')) {
    function eaddslashes($value, $ckmq = 1)
    {
        $value = $value === null ? '' : (string) $value;

        if ((int) $ckmq === 0) {
            return addslashes($value);
        }

        return richtext_instance()->addSlashes($value, 1);
    }
}

if (!function_exists('eaddslashes2')) {
    function eaddslashes2($value, $ckmq = 1)
    {
        $value = $value === null ? '' : (string) $value;

        if ((int) $ckmq === 0) {
            return addslashes(addslashes($value));
        }

        return richtext_instance()->addSlashes($value, 2);
    }
}

if (!function_exists('estripSlashes')) {
    function estripSlashes($value, $ckmq = 1)
    {
        $value = $value === null ? '' : (string) $value;

        if ((int) $ckmq === 1 && !richtext_instance()->getConfig('magic_quotes', false)) {
            return $value;
        }

        return richtext_instance()->stripSlashes($value, 1);
    }
}

if (!function_exists('estripSlashes2')) {
    function estripSlashes2($value, $ckmq = 1)
    {
        $value = $value === null ? '' : (string) $value;

        if ((int) $ckmq === 1 && !richtext_instance()->getConfig('magic_quotes', false)) {
            return $value;
        }

        return richtext_instance()->stripSlashes($value, 2);
    }
}