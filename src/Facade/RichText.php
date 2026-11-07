<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Facade;

use think\Facade;
use Youvision\ThinkRichText\RichText as RichTextInstance;

/**
 * @method static string encode(?string $value) 写入数据库前的转义与过滤
 * @method static string decode(?string $value) 读取数据库后的还原与过滤
 * @method static string escape(?string $value, ?int $flags = null) htmlspecialchars 转义
 * @method static string unescape(?string $value, ?int $flags = null) htmlspecialchars_decode 还原
 * @method static string addSlashes(?string $value, int $level = 1) addslashes 处理
 * @method static string stripSlashes(?string $value, int $level = 1) stripSlashes 处理
 * @method static string filter(?string $value) 仅执行 HTML 安全过滤
 * @method static string plain(?string $html, ?int $length = null) 提取纯文本摘要
 * @method static string summary(?string $html, ?int $length = null) 提取纯文本摘要
 * @method static array encodeFields(array $data, array $fields) 批量写入处理
 * @method static array decodeFields(array $data, array $fields) 批量读取处理
 * @method static mixed getConfig(?string $name = null, mixed $default = null) 获取配置
 */
class RichText extends Facade
{
    protected static function getFacadeClass()
    {
        return RichTextInstance::class;
    }
}