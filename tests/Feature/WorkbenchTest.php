<?php

namespace ApiMedia\Tgs\Tests\Feature;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Filament\Pages\ManageBlockLibrary;
use ApiMedia\Tgs\Filament\Resources\CollectionResource;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Models\Collection;
use ApiMedia\Tgs\Services\BlockTreeAssembler;
use ApiMedia\Tgs\Support\BlockTree;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchPanelProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\App\Tgs\Counter;
use Workbench\App\Tgs\CounterData;
use Workbench\Database\Seeders\DatabaseSeeder;

class WorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected const PANEL_PROVIDER = WorkbenchPanelProvider::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(WorkbenchServiceProvider::class);
    }

    #[Test]
    public function demo_editor_can_use_library_but_cannot_manage_locks_or_delete_everywhere(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::query()->where('email', 'editor@tgs.test')->sole());
        $this->assertTrue(Gate::allows('tgs.access'));
        $this->assertTrue(Gate::allows('tgs.manageLibrary'));
        $this->assertFalse(Gate::allows('tgs.manageLocks'));
        $this->assertFalse(Gate::allows('tgs.deleteEverywhere'));
        $this->actingAs(User::query()->where('email', 'admin@tgs.test')->sole());
        $this->assertTrue(Gate::allows('tgs.manageLocks'));
        $this->assertTrue(Gate::allows('tgs.deleteEverywhere'));
    }

    #[Test]
    public function collection_tools_have_english_labels(): void
    {
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('en');
        $this->actingAs(User::query()->where('email', 'admin@tgs.test')->sole());
        $this->get(CollectionResource::getUrl())
            ->assertOk()->assertSee('Block collections')->assertSee('Name')->assertSee('Description');
        $canvas = app(TgsCanvas::class);
        $this->assertSame('Lock block?', $canvas->lockBlockAction()->getModalHeading());
        $this->assertSame('Unlock block?', $canvas->unlockBlockAction()->getModalHeading());
    }

    #[Test]
    public function guide_links_to_library_and_collections_and_explains_manual_scenarios(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@tgs.test')->sole());
        $this->get('/admin/demo-guide')->assertOk()
            ->assertSee('Библиотека блоков')->assertSee('Коллекции блоков')
            ->assertSee('История')->assertSee('Корзина');
        $this->get(ManageBlockLibrary::getUrl())->assertOk();
        $this->get(CollectionResource::getUrl())->assertOk();
    }

    #[Test]
    public function showcase_seeds_library_and_collections_without_overwriting_edited_content(): void
    {
        $this->seed(DatabaseSeeder::class);
        $library = Canvas::named(TgsNamedCanvas::BlockLibrary);
        $reader = app(BlockTreeAssembler::class);
        $this->assertNotEmpty($reader->assemble($library->hostDescriptor()));
        $collection = Collection::query()->where('name', 'Демо: готовая секция')->sole();
        $this->assertNotEmpty($reader->assemble($collection->canvas('blocks')->hostDescriptor()));
        $demo = TgsDemo::query()->where('name', 'TGS Workbench')->sole();
        BlockTree::make()->content('<p>Моя правка</p>')->persistTo($demo, 'content');

        $this->seed(DatabaseSeeder::class);

        $this->assertStringContainsString('Моя правка', json_encode($demo->fresh()->getContent(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, Collection::query()->where('name', 'Демо: готовая секция')->count());
    }

    #[Test]
    public function seeded_demo_has_real_media_and_nested_typed_content_without_unknown_blocks(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = TgsDemo::query()->where('name', 'TGS Workbench')->sole();
        $content = $demo->getContent();
        $this->assertSame(['content', 'picture', 'gallery', 'tabs', 'embedded'], array_map(fn ($block) => $block->type->value, $content));
        $this->assertNotNull($content[1]->image);
        $this->assertCount(2, $content[2]->images);
        $this->assertCount(2, $content[3]->items);
    }

    #[Test]
    public function seeded_counter_saves_through_the_canvas_and_survives_reseeding(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@tgs.test')->sole());
        $demo = TgsDemo::query()->where('name', DatabaseSeeder::COUNTER_DEMO)->sole();
        $counter = $demo->getContent()[0];
        $this->assertInstanceOf(CounterData::class, $counter);
        $this->assertSame(2, $counter->count);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->assertSee('&middot; 2', escape: false)
            ->mountAction('editBlockAction', ['block' => $counter->id, 'type' => Counter::TYPE, 'isRoot' => true])
            ->assertSet('mountedActions.0.data.count', 2)
            ->set('mountedActions.0.data.count', 0)
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertSee('&middot; 0', escape: false);

        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, $demo->fresh()->getContent()[0]->count);
        $this->get('/admin/tgs-demos/'.$demo->getKey().'/edit')
            ->assertOk()
            ->assertSee('&middot; 0', escape: false);
    }

    #[Test]
    public function demo_resource_and_json_page_are_available_to_the_logged_in_demo_user(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'admin@tgs.test')->sole();
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
        $this->actingAs(User::query()->where('email', 'admin@tgs.test')->sole());
        $demo = TgsDemo::query()->where('name', 'TGS Workbench')->sole();

        $this->get('/admin/tgs-demos/'.$demo->getKey().'/edit')
            ->assertOk()
            ->assertDontSee('tgs::filament.');
    }
}
