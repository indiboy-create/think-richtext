<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Support;

final class Reporter
{
    public static function containerHas(string $abstract): bool
    {
        if (!class_exists(\think\Container::class)) {
            return false;
        }

        try {
            return \think\Container::getInstance()->has($abstract);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function warning(string $message): void
    {
        $message = '[think-richtext] ' . $message;

        if (class_exists(\think\facade\Log::class)) {
            try {
                \think\facade\Log::warning($message);
                return;
            } catch (\Throwable $e) {
                // 日志服务不可用时回退到 PHP 错误日志
            }
        }

        error_log($message);
    }
}
