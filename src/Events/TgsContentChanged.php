<?php

namespace ApiMedia\Tgs\Events;

use ApiMedia\Tgs\Support\HostDescriptor;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Контент ТГС-хоста изменён — сигнал для инвалидации кеша страницы/товара и живого
 * обновления открытых канвасов той же группы (ADR-019). Потребители подписываются и
 * бастят свой кеш по ключу host'а; канвас/персистентность про конкретные кеши не знают.
 */
class TgsContentChanged
{
    use Dispatchable;

    public function __construct(public readonly HostDescriptor $host) {}
}
