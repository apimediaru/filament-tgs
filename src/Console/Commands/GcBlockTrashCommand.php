<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Console\Commands;

use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function config;
use function now;

/**
 * GC для корзины ТГС-блоков (Этап 4 «корзина и восстановление», Слайс E, Приложение А §8):
 * физически стирает `block_deletions`-записи (и их поддеревья мягко удалённых `blocks`) старше
 * `config('tgs.trash_retention_days')`. Образец структуры/стиля логов —
 * `GcOrphanedEmbeddedCanvasesCommand`.
 *
 * Требование 8.4 — перед физическим удалением КАЖДОЙ записи явно проверяется отсутствие живых
 * `BlockPlacement` на любой блок поддерева СНАРУЖИ этого же поддерева через общий примитив
 * `CanvasPersistence::livePlacementsOutsideSubtree()` (тот же принцип запроса, что и приватная
 * `cascadeOwnedSubtree()`'s `stillUsedElsewhere` — placement, чей `container_id` сам входит в
 * поддерево, это сохранённое ради восстановления внутреннее ребро дерева, не "живое
 * использование"; см. `tgs:trash-stuck-report` — второй потребитель того же примитива, для
 * диагностики, какие именно записи он вечно пропускает). Если такая ссылка найдена хоть у одного
 * блока поддерева —
 * ВСЯ запись пропускается этим циклом (не только тот один блок): `block_deletions` — одна
 * строка на всю операцию восстановления, частичное физическое удаление части поддерева
 * сделало бы `restoreBlock()` неконсистентным для оставшихся блоков той же записи.
 *
 * Требование 8.2 — порядок: блоки поддерева удаляются раньше записи `block_deletions`. На
 * практике для блока-КОРНЯ это дополнительно обеспечено самой схемой (`block_deletions.block_id`
 * — `cascadeOnDelete()` на `blocks.id`, см. миграцию Слайса A) — удаление корня уже сносит
 * журнальную строку сам по себе; явный `$deletion->delete()` ниже — идемпотентный no-op в этом
 * случае, но остаётся обязательным для случая пустого поддерева (запись без единого блока с
 * этим `transaction_id` — уже восстановлена ранее, но журнальная строка осталась).
 */
class GcBlockTrashCommand extends Command
{
    protected $signature = 'tgs:gc-block-trash';

    protected $description = 'Purge block_deletions entries (and their soft-deleted subtrees) past the trash retention window.';

    public function handle(CanvasPersistence $persistence): int
    {
        $retentionDays = (int) config('tgs.trash_retention_days', 60);
        $cutoff = now()->subDays($retentionDays);

        $expired = TgsModels::deletion()::query()->where('created_at', '<', $cutoff)->get();

        $processed = 0;
        $blocksDeleted = 0;
        $skipped = 0;

        foreach ($expired as $deletion) {
            DB::transaction(function () use ($deletion, $persistence, &$processed, &$blocksDeleted, &$skipped): void {
                $blockIds = TgsModels::block()::query()
                    ->where('deleted_op_id', $deletion->transaction_id)
                    ->pluck('id')
                    ->all();

                if ($blockIds !== []) {
                    $liveElsewhere = $persistence->livePlacementsOutsideSubtree($blockIds)->isNotEmpty();

                    if ($liveElsewhere) {
                        $skipped++;
                        $this->warn("Skipped block_deletions [{$deletion->id}]: subtree still has a live placement outside it.");

                        return;
                    }

                    $blocks = TgsModels::block()::query()->whereIn('id', $blockIds)->get();
                    $blocks->each->delete();
                    $blocksDeleted += $blocks->count();
                }

                $deletion->delete();
                $processed++;
            });
        }

        $this->info("Processed {$processed} expired trash entry(ies): deleted {$blocksDeleted} block(s), skipped {$skipped} (still referenced elsewhere).");

        return self::SUCCESS;
    }
}
