<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Support\BlockTreeText;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `toText()` для блоков, чей собственный текст сегодня выпадает из extractText()
 * (питает fullText Яндекс-фида): заголовки Collapse/TabItem/AccordionItem, подпись
 * Picture (с фолбэком на title) и текст кнопки Popup.
 */
class BlockToTextTest extends TestCase
{
    private function makeContentBlock(string $content, string $id = 'content-id'): array
    {
        return [
            'type' => 'content',
            'data' => [
                'id' => $id,
                'content' => $content,
            ],
        ];
    }

    private function makeCollapseBlock(string $title, array $content = []): array
    {
        return [
            'type' => 'collapse',
            'data' => [
                'id' => 'collapse-id',
                'title' => $title,
                'content' => $content,
            ],
        ];
    }

    private function makeTabItemBlock(string $title, array $content = []): array
    {
        return [
            'type' => 'tab_item',
            'data' => [
                'id' => 'tab-item-id',
                'title' => $title,
                'content' => $content,
            ],
        ];
    }

    private function makeAccordionItemBlock(string $title, array $content = []): array
    {
        return [
            'type' => 'accordion_item',
            'data' => [
                'id' => 'accordion-item-id',
                'title' => $title,
                'content' => $content,
            ],
        ];
    }

    private function makePictureBlock(?string $caption, ?string $title, string $id = 'picture-id'): array
    {
        return [
            'type' => 'picture',
            'data' => [
                'id' => $id,
                'caption' => $caption,
                'title' => $title,
            ],
        ];
    }

    private function makePopupBlock(string $buttonLabel, array $content = []): array
    {
        return [
            'type' => 'popup',
            'data' => [
                'id' => 'popup-id',
                'button_label' => $buttonLabel,
                'content' => $content,
            ],
        ];
    }

    #[Test]
    public function it_extracts_collapse_title_together_with_tab_item_title_and_nested_content(): void
    {
        $data = [
            $this->makeCollapseBlock('Collapse title'),
            $this->makeTabItemBlock('Tab title', content: [
                $this->makeContentBlock('<p>Nested content</p>'),
            ]),
        ];

        $result = BlockTreeText::extractText($data);

        $this->assertStringContainsString('Collapse title', $result);
        $this->assertStringContainsString('Tab title', $result);
        $this->assertStringContainsString('Nested content', $result);
    }

    #[Test]
    public function it_extracts_accordion_item_title(): void
    {
        $data = [$this->makeAccordionItemBlock('Accordion title')];

        $this->assertSame('Accordion title', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_extracts_picture_caption(): void
    {
        $data = [$this->makePictureBlock('A caption', 'A title')];

        $this->assertSame('A caption', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_falls_back_to_picture_title_when_caption_is_empty(): void
    {
        $data = [$this->makePictureBlock('', 'Fallback title')];

        $this->assertSame('Fallback title', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_falls_back_to_picture_title_when_caption_is_missing(): void
    {
        $data = [$this->makePictureBlock(null, 'Fallback title')];

        $this->assertSame('Fallback title', BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_returns_null_when_picture_has_neither_caption_nor_title(): void
    {
        $data = [$this->makePictureBlock(null, null)];

        $this->assertNull(BlockTreeText::extractText($data));
    }

    #[Test]
    public function it_extracts_popup_button_label_together_with_nested_content(): void
    {
        $data = [
            $this->makePopupBlock('Open popup', content: [
                $this->makeContentBlock('<p>Popup body</p>'),
            ]),
        ];

        $result = BlockTreeText::extractText($data);

        $this->assertStringContainsString('Open popup', $result);
        $this->assertStringContainsString('Popup body', $result);
    }
}
