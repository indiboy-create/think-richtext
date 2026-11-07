<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Model\Concern;

use Youvision\ThinkRichText\RichText;

trait HasRichText
{
    /**
     * 模型可通过 protected $richTextFields = ['content', 'summary']; 指定字段。
     */
    public function richTextFields(): array
    {
        $fields = property_exists($this, 'richTextFields') ? (array) $this->richTextFields : [];
        $global = (array) $this->richText()->getConfig('fields', []);

        return array_values(array_unique(array_merge($global, $fields)));
    }

    protected function isRichTextField($name): bool
    {
        $fields = $this->richTextFields();

        return $fields !== [] && in_array((string) $name, $fields, true);
    }

    protected function richText(): RichText
    {
        if (function_exists('app')) {
            try {
                $instance = app('richtext');
                if ($instance instanceof RichText) {
                    return $instance;
                }
            } catch (\Throwable $e) {
                // 未注册服务时回退到独立实例
            }

            try {
                $instance = app(RichText::class);
                if ($instance instanceof RichText) {
                    return $instance;
                }
            } catch (\Throwable $e) {
                // 未注册服务时回退到独立实例
            }
        }

        return new RichText();
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