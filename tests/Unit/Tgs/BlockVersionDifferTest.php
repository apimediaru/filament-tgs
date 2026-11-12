<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Unit\Tgs;

use ApiMedia\Tgs\Support\BlockVersionDiffer;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BlockVersionDifferTest extends TestCase
{
    private BlockVersionDiffer $differ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->differ = new BlockVersionDiffer;
    }

    #[Test]
    public function differing_data_produces_a_non_empty_diff_html(): void
    {
        $html = $this->differ->render(['text' => 'A'], ['text' => 'B']);

        $this->assertNotSame('', trim($html));
        $this->assertStringContainsString('A', $html);
        $this->assertStringContainsString('B', $html);
    }

    #[Test]
    public function identical_data_produces_no_changed_lines(): void
    {
        $htmlSame = $this->differ->render(['text' => 'A'], ['text' => 'A']);
        $htmlChanged = $this->differ->render(['text' => 'A'], ['text' => 'B']);

        $this->assertNotSame($htmlChanged, $htmlSame);
    }

    #[Test]
    public function stylesheet_is_not_empty(): void
    {
        $this->assertNotSame('', trim($this->differ->styleSheet()));
    }

    #[Test]
    public function table_headers_are_translated_to_russian(): void
    {
        $html = $this->differ->render(['text' => 'A'], ['text' => 'B']);

        // SideBySide (two-column old|new) has no separate "differences" header column —
        // unlike Inline, which this renderer replaced (see BlockVersionDiffer's docblock).
        $this->assertStringContainsString('Было', $html);
        $this->assertStringContainsString('Стало', $html);
        $this->assertStringNotContainsString('>Old<', $html);
        $this->assertStringNotContainsString('>New<', $html);
    }
}
