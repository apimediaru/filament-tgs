<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Services\BlockChildPolicy;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BlockChildPolicyTest extends TestCase
{
    protected BlockChildPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(BlockChildPolicy::class);
    }

    private function blockWithAllowed(array $allowed): TgsBlock
    {
        $block = new class extends TgsBlock
        {
            public array $allowed = [];

            public static function blockType(): ContentBlockType
            {
                return ContentBlockType::Content;
            }

            public function blockAllowedChildren(): array
            {
                return $this->allowed;
            }
        };

        $block->allowed = $allowed;

        return $block;
    }

    #[Test]
    public function no_restriction_returns_empty(): void
    {
        $this->assertSame([], $this->policy->allowedChildrenFor($this->blockWithAllowed([]), 'content'));
    }

    #[Test]
    public function flat_list_applies_to_any_slot(): void
    {
        $block = $this->blockWithAllowed(['picture', 'gallery']);
        $this->assertSame(['picture', 'gallery'], $this->policy->allowedChildrenFor($block, 'content'));
        $this->assertSame(['picture', 'gallery'], $this->policy->allowedChildrenFor($block, 'left'));
    }

    #[Test]
    public function per_slot_map_uses_the_slot_then_wildcard_fallback(): void
    {
        $block = $this->blockWithAllowed([
            'image' => ['picture', 'gallery'],
            '*' => ['content'],
        ]);
        $this->assertSame(['picture', 'gallery'], $this->policy->allowedChildrenFor($block, 'image'));
        $this->assertSame(['content'], $this->policy->allowedChildrenFor($block, 'buttons'));
    }

    #[Test]
    public function per_slot_map_without_matching_slot_or_wildcard_is_unrestricted(): void
    {
        $block = $this->blockWithAllowed(['image' => ['picture']]);
        $this->assertSame([], $this->policy->allowedChildrenFor($block, 'other'));
    }
}
