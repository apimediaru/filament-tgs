<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

class BlockCollectionData
{
    /** @return list<object> */
    public static function fromStored(array $rawBlocks, Model $model): array
    {
        $out = [];

        foreach ($rawBlocks as $raw) {
            if (! is_array($raw) || ! isset($raw['type']) || ! is_array($raw['data'] ?? null)) {
                self::reportMalformed($raw, $model);

                continue; // not a builder block
            }

            $out[] = self::resolveOne((string) $raw['type'], $raw['data'], $model);
        }

        return $out;
    }

    /**
     * Observability for the silent skip above: an entry that made it into the raw
     * array but fails the builder-block shape check is data loss on the hot path
     * (News/Article/Contest getContent()). Report it so
     * it's discoverable instead of vanishing without a trace.
     */
    private static function reportMalformed(mixed $raw, Model $model): void
    {
        $type = is_array($raw) && isset($raw['type']) ? (string) $raw['type'] : null;

        report(new RuntimeException(sprintf(
            'BlockCollectionData::fromStored skipped a malformed block entry on %s#%s%s',
            $model::class,
            $model->getKey() ?? 'null',
            $type !== null ? " (type: {$type})" : '',
        )));
    }

    private static function resolveOne(string $type, array $data, Model $model): object
    {
        $class = BlockRegistry::resolve($type);

        if ($class === null) {
            return UnknownBlockData::fromLegacy($type, $data);
        }

        try {
            return $class::fromStored($data, $model);
        } catch (Throwable $e) {
            report($e); // graceful degrade (#13): one bad block must not 500 the page

            return UnknownBlockData::fromLegacy($type, $data);
        }
    }
}
