<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Support\HostDescriptor;

use function array_key_exists;

/**
 * Fan-out инвалидации: даёт TgsContentChanged на сам изменённый хост И на каждый другой
 * корневой хост, где присутствует любой из изменённых блоков (синхрон). Reverse-lookup
 * по block_placements вверх до корней делегирован `CanvasPersistence::resolveUsagePlaces()`
 * (публичный переиспользуемый примитив — панель «где используется» вызывает его напрямую).
 * Дедуп по дескриптору — на стороне этого класса, т.к. resolveUsagePlaces() работает
 * с одним blockId за раз, а сюда может прийти несколько изменённых блоков разом.
 */
class TgsContentChangeNotifier
{
    public function __construct(private readonly CanvasPersistence $persistence) {}

    /** @param  list<string>  $changedBlockIds */
    public function notify(HostDescriptor $host, array $changedBlockIds): void
    {
        $roots = [];
        $this->addRoot($roots, $host->containerType, $host->containerId, $host->slot);

        foreach ($changedBlockIds as $blockId) {
            foreach ($this->persistence->resolveUsagePlaces($blockId) as $descriptor) {
                $this->addRoot($roots, $descriptor->containerType, $descriptor->containerId, $descriptor->slot);
            }
        }

        foreach ($roots as $descriptor) {
            TgsContentChanged::dispatch($descriptor);
        }
    }

    /** @param  array<string, HostDescriptor>  $roots */
    private function addRoot(array &$roots, string $type, string $id, string $slot): void
    {
        $key = "{$type}\0{$id}\0{$slot}";
        if (! array_key_exists($key, $roots)) {
            $roots[$key] = new HostDescriptor($type, $id, $slot);
        }
    }
}
