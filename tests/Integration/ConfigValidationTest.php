<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Youvision\ThinkRichText\Exception\RichTextException;
use Youvision\ThinkRichText\RichText;

final class ConfigValidationTest extends TestCase
{
    public function testInvalidSlashModeIsRejected(): void
    {
        $this->expectException(RichTextException::class);
        $this->expectExceptionMessage('slash_mode');

        new RichText(['slash_mode' => 'weird']);
    }

    public function testInvalidTemplateEscapeIsRejected(): void
    {
        $this->expectException(RichTextException::class);
        $this->expectExceptionMessage('template_escape');

        new RichText(['template_escape' => 'full2']);
    }

    public function testNegativeInputLimitIsRejected(): void
    {
        $this->expectException(RichTextException::class);
        $this->expectExceptionMessage('max_input_length');

        new RichText(['max_input_length' => -1]);
    }

    public function testInvalidPurifierTidyLevelIsRejected(): void
    {
        $this->expectException(RichTextException::class);
        $this->expectExceptionMessage('purifier.tidy_level');

        new RichText(['purifier' => ['tidy_level' => 'extreme']]);
    }

    public function testPurifierConfigIsDeepMergedWithDefaults(): void
    {
        $richText = new RichText(['purifier' => ['cache_path' => sys_get_temp_dir()]]);

        $purifier = (array) $richText->getConfig('purifier');

        self::assertTrue((bool) $purifier['enabled']);
        self::assertSame('none', $purifier['tidy_level']);
        self::assertSame(sys_get_temp_dir(), $purifier['cache_path']);
    }
}
