<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Console\Commands;

use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function config;
use function now;

/** Removes expired unclaimed canvases, preserving the model's content deletion cascade. */
class GcOrphanedEmbeddedCanvasesCommand extends Command
{
    protected $signature = 'tgs:gc-embedded-canvases';

    protected $description = 'Delete unclaimed embedded canvases after their grace period.';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('tgs.embedded_canvas_grace_hours', 24));
        $count = DB::transaction(function () use ($cutoff): int {
            $orphans = TgsModels::canvas()::query()
                ->where('kind', 'embedded')->whereNull('owner_key')
                ->where(fn ($query) => $query->where('released_at', '<', $cutoff)
                    ->orWhere(fn ($query) => $query->whereNull('released_at')->where('created_at', '<', $cutoff)))
                ->orderBy('id')->lockForUpdate()->get();
            $orphans->each->delete();

            return $orphans->count();
        });
        $this->info("Deleted {$count} orphaned embedded canvas(es).");

        return self::SUCCESS;
    }
}
