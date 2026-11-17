<?php

namespace Workbench\App\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\TgsBlock;
use Filament\Forms\Components\ViewField;

class Counter extends TgsBlock
{
    public const TYPE = 'counter';

    public static function blockType(): string
    {
        return self::TYPE;
    }

    public static function dataClass(): string
    {
        return CounterData::class;
    }

    public function getBlockLabel(): string
    {
        return __('tgs-workbench::counter.label');
    }

    public function blockPreview(array $data, Block $block): ?string
    {
        $count = CounterData::fromStored($data, $block)->count;

        return $count === null ? null : (string) $count;
    }

    public function schema(): array
    {
        return [
            ViewField::make('count')
                ->label($this->getBlockLabel())
                ->view('tgs-workbench::counter')
                ->default(0)
                ->required()
                ->rule('integer:strict'),
        ];
    }
}
