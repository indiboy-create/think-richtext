<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Tests\Integration;

use Youvision\ThinkRichText\Model\Concern\HasRichText;
use Youvision\ThinkRichText\RichText;
use Youvision\ThinkRichText\Service;

class StubModel
{
    protected array $data = [];

    public function setAttr($name, $value, $data = [])
    {
        $this->data[$name] = $value;
        return $this;
    }

    public function getAttr($name)
    {
        return $this->data[$name] ?? null;
    }

    public function getData($name)
    {
        return $this->data[$name] ?? null;
    }
}

class ArticleStub extends StubModel
{
    use HasRichText;

    protected $richTextFields = ['content'];

    public function currentRichText(): RichText
    {
        return $this->richText();
    }
}

class ConfiguredFieldsStub extends StubModel
{
    use HasRichText;
}

final class HasRichTextTest extends AppTestCase
{
    public function testSetAndGetRichTextField(): void
    {
        $app = $this->createApp();
        (new Service($app))->register();
        $model = new ArticleStub();

        $model->setAttr('content', '<p>你好</p>');

        self::assertStringContainsString('&lt;p&gt;', $model->getRawRichText('content'));
        self::assertSame('<p>你好</p>', $model->getAttr('content'));
    }

    public function testTraitUsesContainerInstance(): void
    {
        $app = $this->createApp();
        (new Service($app))->register();
        $model = new ArticleStub();

        self::assertSame($app->make('richtext'), $model->currentRichText());
    }

    public function testNonRichTextFieldIsNotChanged(): void
    {
        $app = $this->createApp();
        (new Service($app))->register();
        $model = new ArticleStub();

        $model->setAttr('title', '<p>标题</p>');

        self::assertSame('<p>标题</p>', $model->getAttr('title'));
    }

    public function testGlobalFieldsFromConfigAreUsed(): void
    {
        $app = $this->createApp();
        $app->config->set(['fields' => ['intro']], 'richtext');
        (new Service($app))->register();
        $model = new ConfiguredFieldsStub();

        $model->setAttr('intro', '<b>简介</b>');

        self::assertSame('<b>简介</b>', $model->getAttr('intro'));
    }

    public function testDecodeOnGetFalseReturnsRawValue(): void
    {
        $app = $this->createApp();
        $app->config->set(['decode_on_get' => false], 'richtext');
        (new Service($app))->register();
        $model = new ArticleStub();

        $model->setAttr('content', '<p>你好</p>');

        self::assertStringContainsString('&lt;p&gt;', $model->getAttr('content'));
    }
}
