<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ItemBlockContractTest extends TestCase
{
    #[Test]
    public function tab_item_declares_a_content_slot(): void
    {
        $class = ContentBuilderFactory::blockClassFor('tab_item');

        $this->assertNotNull($class);
        $this->assertSame(['content'], $class::make()->blockSlots());
    }

    #[Test]
    public function accordion_item_declares_a_content_slot(): void
    {
        $class = ContentBuilderFactory::blockClassFor('accordion_item');

        $this->assertNotNull($class);
        $this->assertSame(['content'], $class::make()->blockSlots());
    }
}
