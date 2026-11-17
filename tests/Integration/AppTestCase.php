<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Tests\Integration;

use PHPUnit\Framework\TestCase;
use think\App;

abstract class AppTestCase extends TestCase
{
    /** @var list<string> */
    protected array $roots = [];

    protected function createApp(): App
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'think-richtext-test-' . bin2hex(random_bytes(6));
        mkdir($root . DIRECTORY_SEPARATOR . 'runtime', 0775, true);
        $this->roots[] = $root;

        return new App($root);
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeDirectory($root);
        }

        $this->roots = [];
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? rmdir($path) : unlink($path);
        }

        rmdir($directory);
    }
}
