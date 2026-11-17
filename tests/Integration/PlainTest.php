<?php

declare(strict_types=1);

namespace Youvision\ThinkRichText\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Youvision\ThinkRichText\RichText;

final class PlainTest extends TestCase
{
    public function testAlreadyDecodedPlainSkipsRedundantDecode(): void
    {
        $richText = new class extends RichText {
            public int $decodeCalls = 0;

            public function decode(?string $value): string
            {
                $this->decodeCalls++;
                return parent::decode($value);
            }
        };

        $text = $richText->plain('<p>你好<strong>世界</strong></p>', null, true);

        self::assertSame('你好世界', $text);
        self::assertSame(0, $richText->decodeCalls);
    }

    public function testDefaultPlainStillDecodesStoredValue(): void
    {
        $richText = new class extends RichText {
            public int $decodeCalls = 0;

            public function decode(?string $value): string
            {
                $this->decodeCalls++;
                return parent::decode($value);
            }
        };

        $encoded = $richText->encode('<p>你好</p>');

        self::assertSame('你好', $richText->plain($encoded));
        self::assertSame(1, $richText->decodeCalls);
    }
}
