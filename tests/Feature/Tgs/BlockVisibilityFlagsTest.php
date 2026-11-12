<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BlockVisibilityFlagsTest extends TestCase
{
    #[Test]
    public function only_item_blocks_are_child_only(): void
    {
        $this->assertTrue(ContentBuilderFactory::blockClassFor('tab_item')::make()->isChildOnlyBlock());
        $this->assertTrue(ContentBuilderFactory::blockClassFor('accordion_item')::make()->isChildOnlyBlock());
        $this->assertFalse(ContentBuilderFactory::blockClassFor('tabs')::make()->isChildOnlyBlock());
        $this->assertFalse(ContentBuilderFactory::blockClassFor('content')::make()->isChildOnlyBlock());
    }
}
