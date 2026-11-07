<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText;

use think\Service as BaseService;

class Service extends BaseService
{
    public function register(): void
    {
        $configFile = dirname(__DIR__) . '/config/richtext.php';
        $default    = is_file($configFile) ? (array) include $configFile : [];
        $current    = (array) $this->app->config->get('richtext', []);
        $config     = array_merge($default, $current);

        $this->app->config->set($config, 'richtext');

        $instance = new RichText($config);

        $this->app->instance('richtext', $instance);
        $this->app->instance(RichText::class, $instance);
    }

    public function boot(): void
    {
    }
}