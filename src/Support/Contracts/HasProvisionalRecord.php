<?php

namespace ApiMedia\Tgs\Support\Contracts;

/**
 * Модель, чья запись может быть создана системой лениво — «по первому реальному
 * действию», которому нужен id (например, загрузка медиа до явного сохранения
 * формы) — а не только по явному save() пользователя.
 *
 * @see docs/superpowers/specs/2026-07-16-provisional-host-records-design.md
 */
interface HasProvisionalRecord
{
    /**
     * Запись создана системой автоматически и ещё не подтверждена реальным
     * сохранением формы (есть значение в provisional_at).
     */
    public function isProvisional(): bool;

    /**
     * Снимает provisional-статус. Вызывается автоматически из saving()-хука трейта при
     * обнаружении реального пользовательского ввода — вручную не вызывать.
     */
    public function promote(): void;

    /**
     * Единая точка входа «мне срочно нужен id». Идемпотентна в рамках текущей
     * сессии формы: повторный вызов переиспользует уже созданную запись.
     * Опциональный `$token` (design: provisional-per-tab-identity) namespaces
     * резолв per-tab — см. `HasProvisionalRecordTrait::provisional()`.
     */
    public static function provisional(?string $token = null): static;

    /**
     * Черновик текущей сессии для `$token` или null — без создания новой записи.
     * Резолв только через session(): сам токен доступа к чужому черновику не даёт.
     */
    public static function findProvisional(?string $token = null): ?static;
}
