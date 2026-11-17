<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="flex items-center gap-3"
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('counter-field', package: 'tgs-workbench') }}"
        x-data="counterField({ wire: $wire, path: @js($getStatePath()) })"
    >
        <output aria-live="polite" x-text="value ?? '—'"></output>
        <x-filament::button
            :id="$getId()"
            type="button"
            :disabled="$isDisabled()"
            :aria-label="__('tgs-workbench::counter.increment')"
            x-on:click="increment()"
        >
            {{ __('tgs-workbench::counter.increment') }}
        </x-filament::button>
    </div>
</x-dynamic-component>
