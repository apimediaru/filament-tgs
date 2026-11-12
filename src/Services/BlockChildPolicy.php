<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Support\TgsBlock;

use function array_is_list;

/**
 * Эффективный allowedChildren для слота (ADR-019): пустой список = без ограничений (`*`).
 * Плоский list — блочный дефолт для всех слотов; ассоциативный map — по слоту с фолбэком
 * на ключ '*'. Возвращает [] когда ограничения нет.
 */
class BlockChildPolicy
{
    /** @return list<string> */
    public function allowedChildrenFor(TgsBlock $container, string $slot): array
    {
        $declared = $container->blockAllowedChildren();

        if ($declared === []) {
            return [];
        }

        if (array_is_list($declared)) {
            /** @var list<string> $declared */
            return $declared;
        }

        if (isset($declared[$slot]) && array_is_list($declared[$slot])) {
            return $declared[$slot];
        }

        if (isset($declared['*']) && array_is_list($declared['*'])) {
            return $declared['*'];
        }

        return [];
    }
}
