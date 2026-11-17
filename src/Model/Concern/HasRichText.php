<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Model\Concern;

use Youvision\ThinkRichText\RichText;
use Youvision\ThinkRichText\Support\Reporter;

trait HasRichText
{
    protected ?array $richTextFieldsCache = null;

    protected ?RichText $richTextInstance = null;

    /**
     * 模型可通过 protected $richTextFields = ['content', 'summary']; 指定字段。
     */
    public function richTextFields(): array
    {
        if ($this->richTextFieldsCache !== null) {
            return $this->richTextFieldsCache;
        }

        $fields = property_exists($this, 'richTextFields') ? (array) $this->richTextFields : [];
        $global = (array) $this->richText()->getConfig('fields', []);

        return $this->richTextFieldsCache = array_values(array_unique(array_merge($global, $fields)));
    }

    protected function isRichTextField($name): bool
    {
        $fields = $this->richTextFields();

        return $fields !== [] && in_array((string) $name, $fields, true);
    }

    protected function richText(): RichText
    {
        if ($this->richTextInstance instanceof RichText) {
            return $this->richTextInstance;
        }

        if (Reporter::containerHas('richtext')) {
            try {
                $instance = \think\Container::getInstance()->make('richtext');
                if ($instance instanceof RichText) {
                    return $this->richTextInstance = $instance;
                }
            } catch (\Throwable $e) {
                Reporter::warning('从容器获取 richtext 服务失败：' . $e->getMessage());
            }
        }

        if (Reporter::containerHas(RichText::class)) {
            try {
                $instance = \think\Container::getInstance()->make(RichText::class);
                if ($instance instanceof RichText) {
                    return $this->richTextInstance = $instance;
                }
            } catch (\Throwable $e) {
                Reporter::warning('从容器获取 RichText 实例失败：' . $e->getMessage());
            }
        }

        return $this->richTextInstance = new RichText();
    }

    public function setAttr($name, $value, $data = [])
    {
        if ($this->isRichTextField($name) && is_string($value) && $value !== '') {
            $value = $this->richText()->encode($value);
        }

        return parent::setAttr($name, $value, $data);
    }

    public function getAttr($name)
    {
        $value = parent::getAttr($name);

        if ($this->isRichTextField($name) && is_string($value) && $value !== '') {
            $richText = $this->richText();

            if ($richText->getConfig('decode_on_get', true)) {
                return $richText->decode($value);
            }
        }

        return $value;
    }

    /**
     * 获取数据库中的原始值，不做 decode。
     */
    public function getRawRichText(string $name): string
    {
        return (string) $this->getData($name);
    }
}