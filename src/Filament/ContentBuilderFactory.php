<?php

namespace ApiMedia\Tgs\Filament;

use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsMedia;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

class ContentBuilderFactory
{
    public static function blockClasses(): array
    {
        return app(TgsBlockRegistry::class)->all();
    }

    public static function blockClassFor(string $type): ?string
    {
        return app(TgsBlockRegistry::class)->classFor($type);
    }

    public static function makeBooleanToggle(string $name, $trueValue = 1, $falseValue = 0, $enabledByDefault = false): ToggleButtons
    {
        $defaultValue = $enabledByDefault ? $trueValue : $falseValue;

        return ToggleButtons::make($name)
            ->grouped()
            ->options([
                $falseValue => $falseLabel ?? __('filament-forms::components.toggle_buttons.boolean.false'),
                $trueValue => $trueLabel ?? __('filament-forms::components.toggle_buttons.boolean.true'),
            ])
            ->colors([
                $falseValue => 'danger',
                $trueValue => 'success',
            ])
            ->default($defaultValue)
            ->afterStateHydrated(fn (Set $set) => $set($name, $state ?? $defaultValue));
    }

    public static function makeAspectRatioSelect($name): Select
    {
        $availableAspectRatio = ['3:2', '2:3', '4:3', '3:4', '5:4', '4:5', '16:9', '16:10', '9:16'];

        return Select::make($name)
            ->options(
                collect($availableAspectRatio)->mapWithKeys(
                    fn ($value) => [$value => $value]
                )->toArray()
            );
    }

    public static function makeEmbeddedWithPreview(
        string $name,
        ?Closure $modifyInputUsing = null,
        ?Closure $modifyToggleUsing = null,
        ?Closure $modifyPreviewUsing = null,
    ) {
        $previewName = '@'.$name.'preview';
        $toggleName = '@'.$name.'togglePreview';

        return Group::make([
            Group::make([
                tap(Textarea::make($name)
                    ->label(__('tgs::filament.forms.presets.embedded_with_preview.fields.source.label'))
                    ->helperText(__('tgs::filament.forms.presets.embedded_with_preview.fields.source.help'))
                    ->live(onBlur: true), fn ($component) => $component->tap($modifyInputUsing)),
                tap(ContentBuilderFactory::makeBooleanToggle($toggleName)
                    ->label(__('tgs::filament.forms.presets.embedded_with_preview.fields.toggle.label'))
                    ->helperText(__('tgs::filament.forms.presets.embedded_with_preview.fields.toggle.help'))
                    ->live()
                    ->dehydrated(false), fn ($component) => $component->tap($modifyToggleUsing)),
            ])->columnSpan(fn (Get $get) => $get($toggleName) ? 1 : 2),
            Group::make([
                tap(TextEntry::make($previewName)
                    ->label(__('tgs::filament.forms.presets.embedded_with_preview.fields.preview.label'))
                    ->html()
                    ->state(static function (Get $get) use ($name) {
                        $source = $get($name);

                        if (empty($source)) {
                            return __('tgs::filament.forms.presets.embedded_with_preview.fields.preview.help');
                        }

                        $src = TgsMedia::parseEmbeddedURL($source);

                        return $src === null
                            ? __('tgs::filament.tgs.blocks.embedded.fields.@preview.vendor_error')
                            : new HtmlString('<iframe class="aspect-video w-full" src="'.e($src).'"></iframe>');
                    }), fn ($component) => $component->tap($modifyPreviewUsing)),
            ])
                ->visible(fn (Get $get) => ! empty($get($toggleName)))
                ->columnSpan(1),
        ])->columns(2);
    }
}
