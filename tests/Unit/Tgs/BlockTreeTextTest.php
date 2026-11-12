<?php

namespace ApiMedia\Tgs\Tests\Unit\Tgs;

use ApiMedia\Tgs\Support\BlockTreeText;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BlockTreeTextTest extends TestCase
{
    private function makeContentBlock(string $content, string $id = 'test-id'): array
    {
        return [
            'type' => 'content',
            'data' => [
                'id' => $id,
                'content' => $content,
            ],
        ];
    }

    private function makeDoubleColumnBlock(array $left = [], array $right = []): array
    {
        return [
            'type' => 'double_column',
            'data' => [
                'id' => 'col-id',
                'left' => $left,
                'right' => $right,
            ],
        ];
    }

    private function makeCodeBlock(string $code, string $id = 'code-id'): array
    {
        return [
            'type' => 'code',
            'data' => [
                'id' => $id,
                'code' => $code,
            ],
        ];
    }

    #[Test]
    public function it_extracts_text_from_root_blocks(): void
    {
        $data = [
            $this->makeContentBlock('<p>hello</p>'),
            $this->makeContentBlock('<p>world</p>'),
        ];

        $this->assertSame('hello world', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_extracts_text_from_nested_blocks(): void
    {
        $data = [
            $this->makeDoubleColumnBlock(
                left: [$this->makeContentBlock('<p>left content</p>')],
                right: [$this->makeContentBlock('<p>right content</p>')],
            ),
        ];

        $result = BlockTreeText::extractText($data);

        $this->assertStringContainsString('left content', $result);
        $this->assertStringContainsString('right content', $result);
    }

    #[Test]
    public function it_returns_null_when_no_text_found(): void
    {
        $data = [
            $this->makeDoubleColumnBlock(),
        ];

        $this->assertNull(BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_strips_html_tags_from_extracted_text(): void
    {
        $data = [
            $this->makeContentBlock('<p>hello <strong>world</strong></p>'),
        ];

        $this->assertSame('hello world', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_respects_max_depth_during_text_extraction(): void
    {
        $data = [
            $this->makeDoubleColumnBlock(
                left: [$this->makeContentBlock('<p>nested content</p>')],
            ),
        ];

        $result = BlockTreeText::extractText($data, maxDepth: 0);

        $this->assertNull($result);
    }

    #[Test]
    public function it_extracts_full_phrase_from_root_blocks(): void
    {
        $data = [
            $this->makeContentBlock('<p>hello</p>'),
            $this->makeContentBlock('<p>world</p>'),
        ];

        $this->assertSame('hello world', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_extracts_full_phrase_from_nested_blocks(): void
    {
        $data = [
            $this->makeDoubleColumnBlock(
                left: [$this->makeContentBlock('<p>hello</p>')],
                right: [$this->makeContentBlock('<p>world</p>')],
            ),
        ];

        $this->assertSame('hello world', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_ignores_code_blocks(): void
    {
        $data = [
            $this->makeContentBlock('<p>hello world</p>'),
            $this->makeCodeBlock('echo "should not appear";'),
        ];

        $this->assertSame('hello world', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_ignores_nested_code_blocks(): void
    {
        $data = [
            $this->makeDoubleColumnBlock(
                left: [$this->makeContentBlock('<p>hello world</p>')],
                right: [$this->makeCodeBlock('echo "should not appear";')],
            ),
        ];

        $this->assertSame('hello world', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_ignores_blocks_with_empty_html(): void
    {
        $data = [
            $this->makeContentBlock('<p></p>'),
            $this->makeContentBlock('<p>hello</p>'),
        ];

        $this->assertSame('hello', BlockTreeText::extractText($data));
    }

    #[Test]
    public function extract_text_dispatches_by_block_type_without_class_key(): void
    {
        $data = [
            [
                'type' => 'content',
                'data' => ['id' => 'a', 'content' => '<p>hello</p>'],
            ],
        ];

        $this->assertSame('hello', BlockTreeText::extractText($data));
    }

    #[Test]
    public function extract_text_skips_unknown_block_types(): void
    {
        $data = [
            ['type' => 'legacy_widget', 'data' => ['id' => 'x', 'content' => '<p>skip me</p>']],
        ];

        $this->assertNull(BlockTreeText::extractText($data));
    }

    #[Test]
    public function extract_text_separates_block_level_elements_with_spaces(): void
    {
        // Баг склейки (виден в прод-фиде): strip_tags срезал блочные теги без разделителей,
        // и абзацы/заголовки/пункты списков сливались («…П1Преимущества Испании:высокий…»).
        // Границы блочных элементов и <br> становятся пробелами — одна непрерывная строка.
        $data = [
            $this->makeContentBlock(
                '<h2>Заголовок</h2><p>Первый абзац.</p><hr>'
                .'<ul><li><p>пункт один;</p></li><li><p>пункт два.</p></li></ul>'
                .'<p>Финал<br>с переносом.</p>'
            ),
        ];

        $this->assertSame(
            'Заголовок Первый абзац. пункт один; пункт два. Финал с переносом.',
            BlockTreeText::extractText($data),
        );
    }
}
