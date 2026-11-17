<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Tests\Integration;

use Youvision\ThinkRichText\Facade\RichText as RichTextFacade;
use Youvision\ThinkRichText\Service;

final class FacadeTest extends AppTestCase
{
    public function testFacadeUsesContainerInstance(): void
    {
        $app = $this->createApp();
        (new Service($app))->register();

        self::assertSame($app->make('richtext'), RichTextFacade::instance());
    }

    public function testFacadeEncodeAndDecode(): void
    {
        $app = $this->createApp();
        (new Service($app))->register();

        $encoded = RichTextFacade::encode('<p>你好</p>');

        self::assertSame('&lt;p&gt;你好&lt;/p&gt;', $encoded);
        self::assertSame('<p>你好</p>', RichTextFacade::decode($encoded));
    }

    public function testHelpersUseContainerInstance(): void
    {
        $app = $this->createApp();
        (new Service($app))->register();

        $html = '<p>你好</p>';

        self::assertSame($app->make('richtext')->encode($html), richtext_encode($html));
        self::assertSame($app->make('richtext'), richtext_instance());
    }
}
