<?php

namespace ApiMedia\Tgs\Enums;

/**
 * Именованные (kind='named') ТГС-канвасы: не привязаны ни к какой Eloquent-модели, живут по
 * фиксированному строковому `key`. Заменяет canvas-роль App\Models\Virtual (CANVAS_DEMO/
 * BLOCK_LIBRARY кейсы VirtualType) — не называется TgsCanvas, чтобы не путать с
 * ApiMedia\Tgs\Livewire\TgsCanvas (сам Livewire-компонент канваса).
 *
 * Не каждый named-канвас обязан иметь тут кейс: Canvas::named()/TgsCanvasField::named()
 * принимают и произвольную строку — разработчик сам решает, когда ключ достаточно значим,
 * чтобы формализовать его здесь, а когда достаточно просто строкового литерала на месте
 * использования. Кэш-ключи named-канваса объявляются не здесь, а в `config/tgs.php`
 * (`named_canvas_cache_keys`) — одинаково для enum-кейсов и строковых ключей.
 */
enum TgsNamedCanvas: string
{
    case CanvasDemo = 'canvas_demo';
    case BlockLibrary = 'library';

    public function label(): string
    {
        return match ($this) {
            self::CanvasDemo => 'ТГС demo-канвас',
            self::BlockLibrary => 'Библиотека блоков',
        };
    }
}
