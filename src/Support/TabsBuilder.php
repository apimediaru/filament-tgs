<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Enums\ContentBlockType;
use Closure;

/**
 * Scoped builder handed to the closure passed to {@see BlockTree::tabs()}: the only
 * way to reach `->tabItem()` is inside that closure, since this class is deliberately
 * not a {@see BlockTree} — there is no `->tabItem()` to call anywhere else.
 */
final class TabsBuilder
{
    /** @var list<array{type: string, data: array<string, mixed>}> */
    private array $items = [];

    /**
     * Bubbled up from each item's {@see BlockTree} — see {@see BlockTree::$pendingMedia}.
     *
     * @var list<array{blockId: string, uuid: string, files: list<string>}>
     */
    private array $pendingMedia = [];

    /**
     * @param  Closure(BlockTree): mixed  $content
     */
    public function tabItem(string $title, Closure $content): static
    {
        $child = new BlockTree;
        $content($child);

        $this->items[] = [
            'type' => ContentBlockType::TabItem->value,
            'data' => [
                'title' => $title,
                'content' => $child->toArray(),
            ],
        ];

        $this->pendingMedia = [...$this->pendingMedia, ...$child->exportPendingMedia()];

        return $this;
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    public function toArray(): array
    {
        return $this->items;
    }

    /** @return list<array{blockId: string, uuid: string, files: list<string>}> */
    public function exportPendingMedia(): array
    {
        return $this->pendingMedia;
    }
}
