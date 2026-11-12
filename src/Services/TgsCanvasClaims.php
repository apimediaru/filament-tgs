<?php

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function array_map;
use function array_unique;
use function array_values;
use function filter_var;
use function in_array;
use function is_string;
use function now;
use function strlen;
use function trim;

/** A claim is a complete saved list, never an incremental attachment. */
final class TgsCanvasClaims
{
    /** @param list<mixed> $ids
     * @param  list<mixed>  $cacheKeys
     */
    public function sync(array $ids, string $ownerKey, array $cacheKeys): void
    {
        if (trim($ownerKey) === '' || strlen($ownerKey) > 255) {
            $this->refuse();
        }
        $ids = array_values(array_unique(array_map(function (mixed $id): int {
            $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value === false) {
                $this->refuse();
            }

            return $value;
        }, $ids)));
        foreach ($cacheKeys as $key) {
            if (! is_string($key) || trim($key) === '') {
                $this->refuse();
            }
        }
        DB::transaction(function () use ($ids, $ownerKey, $cacheKeys): void {
            // A durable owner row serializes even two first/empty-list saves. The lock
            // lives until the outer owner/settings transaction commits, not just sync().
            DB::table('tgs_canvas_claim_locks')->insertOrIgnore(['owner_key' => $ownerKey]);
            DB::table('tgs_canvas_claim_locks')->where('owner_key', $ownerKey)->lockForUpdate()->first();

            $canvases = TgsModels::canvas()::query()
                ->where(fn ($query) => $query->where('owner_key', $ownerKey)->orWhereIn('id', $ids))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($ids as $id) {
                $canvas = $canvases->get($id);
                if ($canvas === null || $canvas->kind !== 'embedded'
                    || ($canvas->owner_key !== null && $canvas->owner_key !== $ownerKey)) {
                    $this->refuse();
                }
            }
            foreach ($canvases as $canvas) {
                if (in_array($canvas->id, $ids, true)) {
                    $canvas->forceFill(['owner_key' => $ownerKey, 'cache_keys' => array_values(array_unique($cacheKeys)), 'claimed_at' => now(), 'released_at' => null])->save();
                } elseif ($canvas->kind === 'embedded') {
                    $canvas->forceFill(['owner_key' => null, 'cache_keys' => [], 'claimed_at' => null, 'released_at' => now()])->save();
                }
            }
        });
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['canvas' => __('tgs::filament.tgs.canvas.claim_conflict')]);
    }
}
