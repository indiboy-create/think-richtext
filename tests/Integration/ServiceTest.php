<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Tests\Integration;

use Youvision\ThinkRichText\RichText;
use Youvision\ThinkRichText\Service;

final class ServiceTest extends AppTestCase
{
    public function testConfigSetSignatureIsConfigFirst(): void
    {
        $app = $this->createApp();
        $app->config->set(['driver' => 'probe'], 'richtext_probe');

        self::assertSame('probe', $app->config->get('richtext_probe.driver'));
    }

    public function testServiceMergesDefaultsWithApplicationConfig(): void
    {
        $app = $this->createApp();
        $app->config->set(['filter_html' => false], 'richtext');

        (new Service($app))->register();

        $config = $app->config->get('richtext');

        self::assertFalse($config['filter_html']);
        self::assertSame('UTF-8', $config['charset']);
        self::assertFalse($app->make('richtext')->getConfig('filter_html'));
    }

    public function testServiceBindsSharedRichTextInstance(): void
    {
        $app = $this->createApp();

        (new Service($app))->register();

        self::assertInstanceOf(RichText::class, $app->make('richtext'));
        self::assertSame($app->make('richtext'), $app->make(RichText::class));
    }
}
