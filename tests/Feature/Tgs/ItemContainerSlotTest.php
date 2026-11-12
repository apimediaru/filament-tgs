<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Services\BlockChildPolicy;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ItemContainerSlotTest extends TestCase
{
    #[Test]
    public function tabs_declares_an_items_slot_limited_to_tab_item(): void
    {
        $tabs = ContentBuilderFactory::blockClassFor('tabs')::make();

        $this->assertSame(['items'], $tabs->blockSlots());
        $this->assertSame(['tab_item'], app(BlockChildPolicy::class)->allowedChildrenFor($tabs, 'items'));
    }

    #[Test]
    public function accordion_declares_an_items_slot_limited_to_accordion_item(): void
    {
        $accordion = ContentBuilderFactory::blockClassFor('accordion')::make();

        $this->assertSame(['items'], $accordion->blockSlots());
        $this->assertSame(['accordion_item'], app(BlockChildPolicy::class)->allowedChildrenFor($accordion, 'items'));
    }
}
