<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Fixtures;

use ApiMedia\Tgs\Tests\Feature\Tgs\ApiContractSnapshotTest;

use function array_is_list;
use function array_map;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Golden-test normalizer for {@see ApiContractSnapshotTest}: walks
 * a decoded (assoc-array) JSON payload and replaces every scalar VALUE with the name
 * of its PHP type (`"string"`, `"int"`, `"float"`, `"null"`), preserving keys, nesting
 * and order exactly. That is what makes the snapshot stable against ids, URLs, dates
 * and filenames while still failing the instant a field is added, removed, renamed,
 * or changes type.
 *
 * Booleans are the one exception: they normalize to their literal value (`"true"` /
 * `"false"`), not a type name. Unlike ids/URLs/dates, a boolean flag has no volatility
 * to hide — collapsing both `true` and `false` to `"bool"` would make a DTO regression
 * that flips a flag (e.g. accordion `is_open`, code `is_pure`, picture `is_lightbox`)
 * invisible to this snapshot.
 *
 * Lists (sequential-int-keyed arrays) get one extra rule: if every element
 * normalizes to the exact same shape (e.g. N gallery images, N accordion items), the
 * list collapses to a single representative element — so the snapshot pins down the
 * *shape* of an element without also pinning down how many the fixture happens to
 * contain. Lists whose elements normalize differently (e.g. the top-level `blocks`
 * list, one of each contract type) are left untouched, element by element.
 */
final class ApiContractNormalizer
{
    public static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? self::normalizeList($value)
                : self::normalizeAssoc($value);
        }

        return self::normalizeScalar($value);
    }

    /** @param  array<string, mixed>  $value */
    private static function normalizeAssoc(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = self::normalize($item);
        }

        return $out;
    }

    /** @param  list<mixed>  $value */
    private static function normalizeList(array $value): array
    {
        $normalized = array_map(static fn (mixed $item): mixed => self::normalize($item), $value);

        if (count($normalized) <= 1) {
            return $normalized;
        }

        $first = $normalized[0];

        foreach ($normalized as $item) {
            if ($item !== $first) {
                // Shapes differ across elements — keep every element, nothing to collapse.
                return $normalized;
            }
        }

        return [$first];
    }

    private static function normalizeScalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => $value ? 'true' : 'false',
            default => 'null', // null is the only remaining case once arrays are handled above
        };
    }
}
