<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Console\Commands;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Console\Command;

/**
 * §3.2.3 ТЗ (Этап 1 «канвасы как сущность»): read-only diff между ApiMedia\Tgs\Enums\TgsNamedCanvas
 * (объявленные named-канвасы) и `canvases` (kind='named') строками в БД — показывает
 * осиротевшие `key`, у которых нет соответствующего кейса енама. Не удаляет ничего сам —
 * решение об удалении осиротевшей строки остаётся за оператором.
 */
class SyncCanvasesCommand extends Command
{
    protected $signature = 'tgs:sync-canvases';

    protected $description = 'Diff named-kind canvases against ApiMedia\Tgs\Enums\TgsNamedCanvas — report orphaned rows, change nothing.';

    public function handle(): int
    {
        $declaredKeys = collect(TgsNamedCanvas::cases())->map(fn (TgsNamedCanvas $case): string => $case->value);

        $existing = TgsModels::canvas()::query()->where('kind', 'named')->pluck('key');

        $orphaned = $existing->diff($declaredKeys);
        $missing = $declaredKeys->diff($existing);

        if ($orphaned->isEmpty() && $missing->isEmpty()) {
            $this->info('Named canvases are in sync — no orphaned or missing rows.');

            return self::SUCCESS;
        }

        foreach ($orphaned as $key) {
            $this->warn("Orphaned named canvas row: key=\"{$key}\" has no matching TgsNamedCanvas case.");
        }

        foreach ($missing as $key) {
            $this->line("Declared but not yet created: key=\"{$key}\" (lazily created on first \ApiMedia\Tgs\Support\TgsModels::canvas()::named() call).");
        }

        return self::SUCCESS;
    }
}
