<?php

namespace ApiMedia\Tgs\Tests\Unit\Tgs;

use ApiMedia\Tgs\Data\Blocks\EmbeddedData;
use ApiMedia\Tgs\Support\TgsMedia;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class EmbeddedUrlTest extends TestCase
{
    public static function rejectedSources(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JaVaScRiPt:alert(1)'],
            ["java\nscript:alert(1)"],
            ['data:text/html,<script>alert(1)</script>'],
            ['file:///etc/passwd'],
            ['//player.example/video'],
            ['/video'],
            ['https:player.example/video'],
            ['https://'],
            ["https://player.example/\r\nvideo"],
            ['<iframe src="javascript:alert(1)"></iframe>'],
            ['<iframe src="jav&#x61;script:alert(1)"></iframe>'],
            ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedSources')]
    public function unsafe_sources_have_no_embed_url_but_keep_the_original_data(string $source): void
    {
        $this->assertNull(TgsMedia::parseEmbeddedURL($source));
        $dto = EmbeddedData::fromStored(['id' => 'embed', 'source' => $source], new TgsDemo);
        $this->assertSame($source, $dto->source);
        $this->assertNull($dto->embedded_src);
        $this->assertArrayHasKey('embedded_src', $dto->toArray());
    }

    public static function supportedSources(): array
    {
        return [
            ['http://player.example/video', 'http://player.example/video'],
            [' https://player.example/video ', 'https://player.example/video'],
            ['<iframe src="https://player.example/video?a=1&amp;b=2"></iframe>', 'https://player.example/video?a=1&b=2'],
            ["<iframe src='https://player.example/video'></iframe>", 'https://player.example/video'],
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ?wmode=transparent'],
        ];
    }

    #[Test]
    #[DataProvider('supportedSources')]
    public function absolute_web_urls_and_provider_conversion_are_preserved(string $source, string $expected): void
    {
        $this->assertSame($expected, TgsMedia::parseEmbeddedURL($source));
    }
}
