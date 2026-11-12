<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Contracts\HasCanvasContent;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class CanvasContentContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tgs_demo_declares_the_contract(): void
    {
        // Regression guard: PHPStan already knows this statically from `implements`,
        // but the test protects against someone dropping the contract from the model.
        // @phpstan-ignore function.alreadyNarrowedType
        $this->assertTrue(is_a(TgsDemo::class, HasCanvasContent::class, true));
    }

    #[Test]
    public function the_contract_is_accessible_through_the_interface_type(): void
    {
        $demo = TgsDemo::factory()->create();

        $this->assertInstanceOf(HasCanvasContent::class, $demo);
        $this->assertNull($demo->getContent());
        $this->assertNull($demo->getContent('content'));
        $this->assertNull($demo->getTextContent());
        $this->assertNull($demo->getTextContent('content'));
    }
}
