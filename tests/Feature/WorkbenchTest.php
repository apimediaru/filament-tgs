<?php

namespace ApiMedia\Tgs\Tests\Feature;

use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

class WorkbenchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeded_demo_has_real_media_and_nested_typed_content_without_unknown_blocks(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = TgsDemo::query()->sole();
        $content = $demo->getContent();
        $this->assertSame(['content', 'picture', 'gallery', 'tabs', 'embedded'], array_map(fn ($block) => $block->type->value, $content));
        $this->assertNotNull($content[1]->image);
        $this->assertCount(2, $content[2]->images);
        $this->assertCount(2, $content[3]->items);
    }

    #[Test]
    public function demo_resource_and_json_page_are_available_to_the_logged_in_demo_user(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->sole();
        $this->get('/admin/login')->assertOk();
        $this->actingAs($user);
        $this->get('/admin/tgs-demos')->assertOk();
        $this->get('/admin/show-json')->assertOk()->assertSee('TGS JSON');
    }

    public static function interfaceLocales(): array
    {
        return [['en'], ['ru']];
    }

    #[Test]
    #[DataProvider('interfaceLocales')]
    public function demo_editor_renders_translated_block_labels(string $locale): void
    {
        app()->setLocale($locale);
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::query()->sole());
        $demo = TgsDemo::query()->sole();

        $this->get('/admin/tgs-demos/'.$demo->getKey().'/edit')
            ->assertOk()
            ->assertDontSee('tgs::filament.');
    }
}
