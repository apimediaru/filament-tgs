<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Support\Tgs;

use ApiMedia\Tgs\Services\CanvasPersistence;
use ReflectionClass;
use ReflectionMethod;

use function in_array;

/**
 * Mirror of {@see TgsCanvasMutators} for the persistence layer: the mutating public methods of
 * CanvasPersistence, which must each fire signalChanged() so no content mutation escapes cache
 * invalidation (backlog #36's sibling on the write path). NON_MUTATING lists genuinely read-only
 * public methods, each with a reason.
 */
final class CanvasPersistenceMutators
{
    /** @var list<string> */
    public const NON_MUTATING = [
        // Validates a recipe's data in memory before any snapshot is written.
        'assertSeedFields',
        // Pure reads — no rows written, so no content change to signal.
        'wouldCreateCycle',
        'subtreeHasReferences',
        'resolveUsagePlaces',
        // Этап 4, Слайс E: BFS-count over BlockPlacement, no rows written — backs the delete
        // confirmation's nested-count wording (see TgsCanvas::nestedLiveDescendantsCount()).
        'countLiveDescendants',
        // Этап 4, Слайс D: read-only lookup (`->value('id')`, never firstOrCreate()) of the
        // `canvases` row id for a host — used by TgsCanvas/TgsTrashDrawer to scope
        // `block_deletions` queries from a render path, which must never have write side
        // effects (see its own docblock for the duplicate-row bug this replaced). No block
        // content is touched, so no signalChanged().
        'findCanvasId',
        // Post-Этап-4 review finding: shared read-only primitive behind tgs:gc-block-trash's
        // Требование 8.4 check and the tgs:trash-stuck-report diagnostic — just a query, no
        // rows written.
        'livePlacementsOutsideSubtree',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        $class = new ReflectionClass(CanvasPersistence::class);
        $classFile = $class->getFileName();
        $names = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getFileName() !== $classFile) {
                continue;
            }
            if (in_array($method->getName(), self::NON_MUTATING, true)) {
                continue;
            }
            $names[] = $method->getName();
        }

        return $names;
    }
}
