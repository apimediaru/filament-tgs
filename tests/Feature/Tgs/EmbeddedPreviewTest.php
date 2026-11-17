<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Blocks\Embedded;
use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Tests\TestCase;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class EmbeddedPreviewTest extends TestCase
{
    public static function forms(): array
    {
        return ['block' => [false], 'preset' => [true]];
    }

    #[Test]
    #[DataProvider('forms')]
    public function preview_renders_https_but_never_an_executable_url(bool $preset): void
    {
        $toggle = $preset ? '@sourcetogglePreview' : '@toggle_preview';
        $component = Livewire::test(EmbeddedPreviewForm::class, ['preset' => $preset])
            ->set('data.source', 'https://player.example/video')
            ->set('data.'.$toggle, 1);

        $component->assertSeeHtml('src="https://player.example/video"');
        $component->set('data.source', 'javascript:alert(document.domain)')
            ->assertDontSeeHtml('<iframe');
    }

    #[Test]
    #[DataProvider('forms')]
    public function preview_escapes_the_url_and_does_not_copy_iframe_attributes(bool $preset): void
    {
        $toggle = $preset ? '@sourcetogglePreview' : '@toggle_preview';
        $component = Livewire::test(EmbeddedPreviewForm::class, ['preset' => $preset])
            ->set('data.source', '<iframe src="https://player.example/video?a=1&amp;b=2" onload="alert(1)" srcdoc="unsafe"></iframe>')
            ->set('data.'.$toggle, 1);

        $html = $component->html();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $frames = $document->getElementsByTagName('iframe');
        $this->assertSame(1, $frames->length);
        $frame = $frames->item(0);
        $this->assertSame('https://player.example/video?a=1&b=2', $frame->getAttribute('src'));
        $this->assertFalse($frame->hasAttribute('onload'));
        $this->assertFalse($frame->hasAttribute('srcdoc'));
        $component->assertSeeHtml('src="https://player.example/video?a=1&amp;b=2"');
        $component->set('data.source', 'https://player.example/video?title="sample"&t=1')
            ->assertSeeHtml('src="https://player.example/video?title=&quot;sample&quot;&amp;t=1"');
    }
}

/** @property Schema $form */
class EmbeddedPreviewForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public array $data = [];

    public bool $preset = false;

    public function mount(bool $preset = false): void
    {
        $this->preset = $preset;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components($this->preset
            ? [ContentBuilderFactory::makeEmbeddedWithPreview('source')]
            : Embedded::make()->schema());
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
