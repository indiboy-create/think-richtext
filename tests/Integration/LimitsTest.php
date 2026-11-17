<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Tests\Integration;

use Youvision\ThinkRichText\Exception\RichTextException;
use Youvision\ThinkRichText\RichText;
use Youvision\ThinkRichText\Service;

final class LimitsTest extends AppTestCase
{
    public function testEncodeRejectsOversizedInput(): void
    {
        $richText = new RichText(['max_input_length' => 8]);

        $this->expectException(RichTextException::class);
        $this->expectExceptionMessage('超过最大长度限制');

        $richText->encode(str_repeat('a', 9));
    }

    public function testDecodeRejectsOversizedInput(): void
    {
        $richText = new RichText(['max_input_length' => 8]);

        $this->expectException(RichTextException::class);

        $richText->decode(str_repeat('a', 9));
    }

    public function testFilterRejectsOversizedInput(): void
    {
        $richText = new RichText(['max_input_length' => 8]);

        $this->expectException(RichTextException::class);

        $richText->filter(str_repeat('a', 9));
    }

    public function testOversizedDataImageIsRemoved(): void
    {
        $richText = new RichText(['max_data_image_bytes' => 4]);
        $image    = base64_encode(str_repeat('x', 100));

        $encoded = $richText->encode('<img src="data:image/png;base64,' . $image . '">');

        self::assertStringNotContainsString('data:image', $encoded);
    }

    public function testDefaultCacheDirectoryUsesAppRuntime(): void
    {
        $app = $this->createApp();
        (new Service($app))->register();

        $app->make('richtext')->encode('<p>缓存</p>');

        $cachePath = rtrim($app->getRuntimePath(), '/\\')
            . DIRECTORY_SEPARATOR . 'think-richtext'
            . DIRECTORY_SEPARATOR . 'htmlpurifier';

        self::assertDirectoryExists($cachePath);
    }

    public function testConfiguredCacheDirectoryIsUsed(): void
    {
        $app = $this->createApp();
        $app->config->set([
            'purifier' => ['cache_path' => $app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'custom-cache'],
        ], 'richtext');
        (new Service($app))->register();

        $app->make('richtext')->encode('<p>缓存</p>');

        self::assertDirectoryExists($app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'custom-cache');
    }
}
