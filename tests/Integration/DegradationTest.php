<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Tests\Integration;

use Youvision\ThinkRichText\Exception\RichTextException;
use Youvision\ThinkRichText\Filter;
use Youvision\ThinkRichText\RichText;

final class UnavailablePurifierFilter extends Filter
{
    protected function purifier(): ?\HTMLPurifier
    {
        return null;
    }

    public function markDisabledByConfig(): void
    {
        $this->purifierDisabledByConfig = true;
    }
}

final class DegradationTest extends AppTestCase
{
    public function testStrictModeThrowsWhenPurifierIsUnavailable(): void
    {
        $filter = new UnavailablePurifierFilter(['strict_mode' => true]);

        $this->expectException(RichTextException::class);
        $this->expectExceptionMessage('HTMLPurifier 不可用');

        $filter->clean('<p>x</p>');
    }

    public function testNonStrictIntentionalDisableStillUsesDomFilter(): void
    {
        $filter = new UnavailablePurifierFilter([
            'purifier' => ['enabled' => false],
            'remove_tags' => ['script'],
        ]);
        $filter->markDisabledByConfig();

        $cleaned = $filter->clean('<p>x</p><script>alert(1)</script>');

        self::assertStringNotContainsString('<script', $cleaned);
        self::assertStringContainsString('<p>x</p>', $cleaned);
    }

    public function testStrictModeDoesNotRejectExplicitPurifierDisable(): void
    {
        $richText = new RichText([
            'strict_mode' => true,
            'purifier' => ['enabled' => false],
        ]);

        $encoded = $richText->encode('<p>x</p><script>alert(1)</script>');

        self::assertStringNotContainsString('<script', $encoded);
    }
}
