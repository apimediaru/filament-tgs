<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Fixtures;

use ApiMedia\Tgs\Data\BlockRegistry;
use ApiMedia\Tgs\Models\Contracts\HasCanvases;
use ApiMedia\Tgs\Support\AccordionBuilder;
use ApiMedia\Tgs\Support\BlockTree;
use ApiMedia\Tgs\Support\TabsBuilder;

/**
 * Canonical fixture for the TGS API contract golden test (see
 * tests/Feature/Tgs/ApiContractSnapshotTest.php): one instance of every top-level
 * type {@see BlockRegistry::map()} resolves — 11 types
 * (content, picture, code, column, collapse, double_column, accordion, tabs, popup,
 * gallery, embedded) — plus both nested-only item DTOs (`tab_item` inside `tabs`,
 * `accordion_item` inside `accordion`).
 *
 * `faq` is deliberately excluded: it is absent from both
 * `ContentBuilderFactory::blockClasses()` and `BlockRegistry::map()`, so a stored
 * `faq` block degrades to `UnknownBlockData` — it is dead, not part of the contract.
 *
 * `picture`/`gallery` attach real files via `->withMedia()` — without it, `image`
 * resolves to `null` and the snapshot would capture nothing about `TgsImageData`'s
 * shape (the very thing this fixture exists to pin down).
 */
final class CanonicalBlockTree
{
    public static function persistTo(HasCanvases $host, string $groupKey): void
    {
        self::build()->persistTo($host, $groupKey);
    }

    public static function build(): BlockTree
    {
        return BlockTree::make()
            ->content('<p>Canonical content block.</p>')
            ->picture('Canonical picture alt')
            ->withMedia(__DIR__.'/images/1.jpg')
            ->code('<?php echo "canonical"; ?>', true)
            ->column(fn (BlockTree $b) => $b->content('<p>Column child.</p>'))
            ->collapse(fn (BlockTree $b) => $b->content('<p>Collapse child.</p>'))
            ->doubleColumn(
                left: fn (BlockTree $b) => $b->content('<p>Left child.</p>'),
                right: fn (BlockTree $b) => $b->content('<p>Right child.</p>'),
            )
            ->accordion(fn (AccordionBuilder $a) => $a->accordionItem(
                'Canonical accordion item',
                fn (BlockTree $b) => $b->content('<p>Accordion item content.</p>'),
                true,
            ))
            ->tabs(fn (TabsBuilder $t) => $t->tabItem(
                'Canonical tab item',
                fn (BlockTree $b) => $b->content('<p>Tab item content.</p>'),
            ))
            ->popup(fn (BlockTree $b) => $b->content('<p>Popup content.</p>'))
            ->gallery()
            ->withMedia([
                __DIR__.'/images/2.jpg',
                __DIR__.'/images/3.jpg',
            ])
            ->embedded('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    }
}
