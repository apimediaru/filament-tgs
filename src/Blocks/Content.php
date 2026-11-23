<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\ContentData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\BlockSpec;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\RichEditor;
use Illuminate\Contracts\Support\Htmlable;

class Content extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-paragraphalphabet-o';

    public static function dataClass(): ?string
    {
        return ContentData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Content;
    }

    public static function seed(?string $html = null): BlockSpec
    {
        return BlockSpec::of(
            static::blockKey(),
            $html === null ? static fn (): array => ['content' => '<p>'.e(fake()->paragraph()).'</p>'] : ['content' => $html],
        );
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.content.fields.content.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            RichEditor::make('content')
                ->label(__('tgs::filament.tgs.blocks.content.label'))
                ->required(),
        ];
    }

    public static function toText(array $data): ?string
    {
        return trim($data['content'] ?? '') ?: null;
    }

    public function blockPreviewFields(): array
    {
        return ['content'];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::content();
    }

    public function blockOptionalFieldsInCollection(): array
    {
        return ['content'];
    }
}
