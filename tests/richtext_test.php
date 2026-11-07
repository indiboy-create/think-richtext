<?php

declare(strict_types=1);

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Youvision\\ThinkRichText\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

if (!function_exists('richtext_encode')) {
    require dirname(__DIR__) . '/src/helper.php';
}

use Youvision\ThinkRichText\RichText;

$richText = new RichText();
$passed   = 0;
$failed   = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
        return;
    }

    $failed++;
    echo "[FAIL] {$name}" . ($detail === '' ? '' : " => {$detail}") . "\n";
}

function containsNone(string $value, array $needles): bool
{
    foreach ($needles as $needle) {
        if (stripos($value, $needle) !== false) {
            return false;
        }
    }

    return true;
}

$cases = [
    '普通富文本'     => '<p>你好 <strong>世界</strong></p>',
    'PHP 标签'       => '<p>hi</p><?php system("id"); ?>',
    'PHP 短标签'     => '<p>x</p><?= $x ?>',
    'ASP 标签'       => '<p>x</p><% Response.Write("x") %>',
    'script 标签'    => '<p>x</p><script>alert(1)</script>',
    '事件属性'       => '<img src="/a.jpg" onerror="alert(1)">',
    'javascript 协议' => '<a href="javascript:alert(1)">x</a>',
    '实体混淆协议'   => '<a href="java&#x73;cript:alert(1)">x</a>',
    'data HTML'      => '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>',
    'style 表达式'   => '<div style="width:expression(alert(1))">x</div>',
    'iframe'         => '<iframe src="https://evil.example.com"></iframe>',
    'svg 脚本'       => '<svg><script>alert(1)</script></svg>',
    '注释'           => '<p>x</p><!--[if IE]><script>alert(1)</script><![endif]-->',
];

$dangerous = ['<?php', '<?=', '<%', '<script', 'onerror=', 'javascript:', 'expression(', '<iframe', '<svg', '<!--'];

foreach ($cases as $name => $html) {
    $encoded = $richText->encode($html);
    check("encode 过滤 {$name}", containsNone($encoded, $dangerous), $encoded);

    $decoded = $richText->decode($encoded);
    check("decode 过滤 {$name}", containsNone($decoded, $dangerous), $decoded);
}

$normal = '<p>你好 <strong>世界</strong></p>';
$encoded = $richText->encode($normal);

check('encode 后不含原始标签', !str_contains($encoded, '<p>'), $encoded);
check('encode 后为 HTML 实体', str_contains($encoded, '&lt;p&gt;'), $encoded);
check('encode/decode 往返', $richText->decode($encoded) === $normal, $richText->decode($encoded));
check('encode 幂等', $richText->encode($encoded) === $encoded, $richText->encode($encoded));

$withDollar = '<p>价格 $100 反斜杠 \\ 路径</p>';
$encodedDollar = $richText->encode($withDollar);

check('模板字符写入转义', !str_contains($encodedDollar, '$'), $encodedDollar);
check('模板字符读取还原', $richText->decode($encodedDollar) === $withDollar, $richText->decode($encodedDollar));

check(
    'base64 图片保留',
    str_contains($richText->encode('<img src="data:image/png;base64,AAAA">'), 'data:image/png'),
    $richText->encode('<img src="data:image/png;base64,AAAA">')
);
check(
    'SVG data 图片移除',
    !str_contains($richText->encode('<img src="data:image/svg+xml;base64,AAAA">'), 'data:image'),
    $richText->encode('<img src="data:image/svg+xml;base64,AAAA">')
);
check(
    '网络安全链接保留',
    str_contains($richText->encode('<a href="https://example.com">x</a>'), 'https://example.com'),
    $richText->encode('<a href="https://example.com">x</a>')
);

check('plain 摘要', $richText->plain('<p>你好<br>世界</p>', 2) === '你好', $richText->plain('<p>你好<br>世界</p>', 2));
check('plain 长度为 0', $richText->plain('<p>你好</p>', 0) === '', $richText->plain('<p>你好</p>', 0));
check('helper 写入', richtext_encode('<p>你好</p>') === $richText->encode('<p>你好</p>'), richtext_encode('<p>你好</p>'));
check('helper 读取', richtext_decode(richtext_encode('<p>你好</p>')) === '<p>你好</p>', richtext_decode(richtext_encode('<p>你好</p>')));
check('兼容 ehtmlspecialchars', ehtmlspecialchars('<b>x</b>', ENT_QUOTES) === '&lt;b&gt;x&lt;/b&gt;', ehtmlspecialchars('<b>x</b>', ENT_QUOTES));
check('兼容 eaddslashes ckmq=0', eaddslashes("O'Reilly", 0) === "O\\'Reilly", eaddslashes("O'Reilly", 0));
check('兼容 eaddslashes2 ckmq=0', eaddslashes2("O'Reilly", 0) === "O\\\\\\'Reilly", eaddslashes2("O'Reilly", 0));
check('兼容 estripSlashes ckmq=1', estripSlashes("O\\'Reilly", 1) === "O\\'Reilly", estripSlashes("O\\'Reilly", 1));
check('兼容 estripSlashes ckmq=0', estripSlashes("O\\'Reilly", 0) === "O'Reilly", estripSlashes("O\\'Reilly", 0));
check('escape', $richText->escape('<b>"x"</b>') === '&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $richText->escape('<b>"x"</b>'));
check('unescape', $richText->unescape('&lt;b&gt;&quot;x&quot;&lt;/b&gt;') === '<b>"x"</b>', $richText->unescape('&lt;b&gt;&quot;x&quot;&lt;/b&gt;'));
check('addSlashes', $richText->addSlashes("O'Reilly") === "O\\'Reilly", $richText->addSlashes("O'Reilly"));
check('stripSlashes', $richText->stripSlashes("O\\'Reilly") === "O'Reilly", $richText->stripSlashes("O\\'Reilly"));
check('addSlashes 双层', $richText->addSlashes("O'Reilly", 2) === "O\\\\\\'Reilly", $richText->addSlashes("O'Reilly", 2));
check('stripSlashes 双层', $richText->stripSlashes("O\\\\\\'Reilly", 2) === "O'Reilly", $richText->stripSlashes("O\\\\\\'Reilly", 2));

$bypassCases = [
    '大写事件属性'     => '<IMG SRC=x ONERROR=alert(1)>',
    '数字实体协议'     => '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>',
    '大小写协议'       => '<a href="  JaVaScRiPt:alert(1)">x</a>',
    'HTML5 冒号实体'   => '<a href="javascript&colon;alert(1)">x</a>',
    '换行混淆协议'     => "<a href=\"java\nscript:alert(1)\">x</a>",
    '制表符实体协议'   => '<a href="jav&#x09;ascript:alert(1)">x</a>',
    'CSS url 协议'     => '<div style="background:url(javascript:alert(1))">x</div>',
    'CSS data 协议'    => '<div style="background:url(data:image/svg+xml;base64,AAAA)">x</div>',
    'iframe srcdoc'    => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    'math 脚本'        => '<math><mtext><script>alert(1)</script></mtext></math>',
    'noscript 绕过'    => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
    'svg onload'       => '<svg/onload=alert(1)>',
    'style 标签'       => '<style>@import "javascript:alert(1)";</style>',
    'srcset 协议'      => '<img srcset="x 1x, javascript:alert(1) 2x">',
    'formaction 协议'  => '<button formaction="javascript:alert(1)">x</button>',
    'poster 协议'      => '<video poster="javascript:alert(1)"></video>',
    'template 脚本'    => '<template><img src=x onerror=alert(1)></template>',
];

foreach ($bypassCases as $name => $html) {
    $caseDangerous = $name === 'noscript 绕过'
        ? array_values(array_diff($dangerous, ['onerror=']))
        : $dangerous;

    $encoded = $richText->encode($html);
    check("encode 绕过防护 {$name}", containsNone($encoded, $caseDangerous), $encoded);

    $decoded = $richText->decode($encoded);
    check("decode 绕过防护 {$name}", containsNone($decoded, $caseDangerous), $decoded);
}

check('HTMLPurifier 依赖已加载', class_exists(\HTMLPurifier::class));

$legacyRichText = new RichText(['purifier' => ['enabled' => false]]);
$legacyEncoded  = $legacyRichText->encode('<p>x</p><script>alert(1)</script>');

check('关闭 HTMLPurifier 回退 DOM 过滤', !str_contains($legacyEncoded, '<script'), $legacyEncoded);

$strictRichText = new RichText(['purifier' => ['allowed' => 'p']]);
$strictDecoded  = $strictRichText->decode($strictRichText->encode('<p><strong>x</strong></p>'));

check('HTMLPurifier 自定义白名单生效', $strictDecoded === '<p>x</p>', $strictDecoded);

$cachePath      = sys_get_temp_dir() . '/think-richtext-test-' . uniqid('', true);
$cacheRichText  = new RichText(['purifier' => ['cache_path' => $cachePath]]);
$cacheRichText->encode('<p>缓存测试</p>');

check('HTMLPurifier 缓存目录创建', is_dir($cachePath), $cachePath);

$iframeRichText = new RichText(['allowed_iframe_hosts' => ['player.bilibili.com', '*.qq.com']]);

check(
    'iframe 白名单保留',
    str_contains($iframeRichText->encode('<iframe src="https://player.bilibili.com/video/1"></iframe>'), 'player.bilibili.com'),
    $iframeRichText->encode('<iframe src="https://player.bilibili.com/video/1"></iframe>')
);
check(
    'iframe 通配域名保留',
    str_contains($iframeRichText->encode('<iframe src="https://v.qq.com/x"></iframe>'), 'v.qq.com'),
    $iframeRichText->encode('<iframe src="https://v.qq.com/x"></iframe>')
);
check(
    'iframe 非白名单移除',
    !str_contains($iframeRichText->encode('<iframe src="https://evil.example.com/x"></iframe>'), 'evil.example.com'),
    $iframeRichText->encode('<iframe src="https://evil.example.com/x"></iframe>')
);

check(
    '相对链接保留',
    str_contains($richText->encode('<a href="/local/path">x</a>'), '/local/path'),
    $richText->encode('<a href="/local/path">x</a>')
);
check(
    'mailto 链接保留',
    str_contains($richText->encode('<a href="mailto:a@example.com">x</a>'), 'mailto:a@example.com'),
    $richText->encode('<a href="mailto:a@example.com">x</a>')
);

$fullEscape = new RichText(['template_escape' => 'full']);
$fullHtml   = '<p>价格 $100 路径 /a/b</p>';
$fullSaved  = $fullEscape->encode($fullHtml);

check('template_escape=full 转义斜杠', str_contains($fullSaved, '&amp;#47;') && !str_contains($fullSaved, '/a/b'), $fullSaved);
check('template_escape=full 读取还原', $fullEscape->decode($fullSaved) === $fullHtml, $fullEscape->decode($fullSaved));

$noneEscape = new RichText(['template_escape' => 'none']);
$noneSaved  = $noneEscape->encode('<p>价格 $100</p>');

check('template_escape=none 保留美元符号', str_contains($noneSaved, '$100'), $noneSaved);
check('template_escape=none 往返', $noneEscape->decode($noneSaved) === '<p>价格 $100</p>', $noneEscape->decode($noneSaved));

$fields = ['content', 'intro'];
$data   = ['content' => '<p>正文</p>', 'intro' => '<b>简介</b>', 'title' => '标题'];
$saved  = $richText->encodeFields($data, $fields);
$loaded = $richText->decodeFields($saved, $fields);

check('批量写入处理', str_contains($saved['content'], '&lt;p&gt;') && $saved['title'] === '标题', $saved['content']);
check('批量读取处理', $loaded['content'] === '<p>正文</p>' && $loaded['intro'] === '<b>简介</b>', $loaded['content']);

echo "\n通过 {$passed} 项，失败 {$failed} 项\n";

exit($failed > 0 ? 1 : 0);