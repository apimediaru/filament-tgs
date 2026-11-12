<?php

namespace ApiMedia\Tgs\Models\Contracts;

use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Support\HostDescriptor;

/**
 * Хост с именованными ТГС-группами (реализация — ApiMedia\Tgs\Models\Concerns\HasCanvases). Контракт
 * нужен, чтобы CapabilityResource мог спросить is_a() и смонтировать канвас на каждую
 * объявленную группу.
 */
interface HasCanvases
{
    /** @return list<string> */
    public static function canvasGroups(): array;

    public function canvas(string $key): Canvas;

    public function canvasDescriptor(string $key): HostDescriptor;
}
