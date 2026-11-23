<?php

namespace ApiMedia\Tgs\Tests\Feature;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Filament\Pages\ManageBlockLibrary;
use ApiMedia\Tgs\Filament\Resources\CollectionResource;
use ApiMedia\Tgs\Filament\Resources\CollectionResource\Pages\CreateCollection;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Models\Collection;
use ApiMedia\Tgs\Services\BlockTreeAssembler;
use ApiMedia\Tgs\Support\BlockTree;
use ApiMedia\Tgs\Tests\TestCase;
use Filament\Resources\Events\RecordCreated;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Workbench\App\Filament\Resources\TgsDemoResource\Pages\CreateTgsDemo;
use Workbench\App\Models\TgsDemo;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\Database\Seeders\DatabaseSeeder;

class WorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(WorkbenchServiceProvider::class);
    }

    /** @return iterable<string, array{class-string<CreateCollection|CreateTgsDemo>, class-string<Collection|TgsDemo>}> */
    public static function provisionalCreatePages(): iterable
    {
        yield 'collection' => [CreateCollection::class, Collection::class];
        yield 'demo host' => [CreateTgsDemo::class, TgsDemo::class];
    }

    /**
     * @param  class-string<CreateCollection|CreateTgsDemo>  $page
     * @param  class-string<Collection|TgsDemo>  $model
     */
    #[Test]
    #[DataProvider('provisionalCreatePages')]
    public function create_form_keeps_its_provisional_record_through_refresh_and_save(string $page, string $model): void
    {
        $this->actingAs(User::query()->create(['name' => 'Editor', 'email' => 'editor@tgs.test', 'password' => 'password']));

        $form = Livewire::withQueryParams(['draft' => 'workbench-provisional'])->test($page);
        $component = $form->instance();
        $this->assertInstanceOf($page, $component);
        $record = $component->getRecord();
        $this->assertInstanceOf($model, $record);
        $this->assertTrue($record->isProvisional());

        $form->call('$refresh')->assertSuccessful();
        $component = $form->instance();
        $this->assertInstanceOf($page, $component);
        $this->assertTrue($component->getSchema('form')?->getModelInstance()?->is($record));

        $form->set('data.name', 'Saved draft');
        $this->assertSame(0, $model::query()->count());
        $form = Livewire::withQueryParams(['draft' => 'workbench-provisional'])->test($page)
            ->assertSet('data.name', 'Saved draft');
        $form->call('create')->assertHasNoFormErrors()->assertRedirect();
        $saved = $model::query()->sole();
        $this->assertSame('Saved draft', $saved->name);
        $this->assertTrue($saved->is($record));
        Livewire::withQueryParams(['draft' => 'workbench-provisional'])->test($page)->assertSet('data.name', null);
    }

    #[Test]
    public function collection_remains_a_draft_when_creation_fails_after_saving(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Editor', 'email' => 'editor@tgs.test', 'password' => 'password']));

        $query = ['draft' => 'collection-failure'];
        $form = Livewire::withQueryParams($query)->test(CreateCollection::class)
            ->set('data.name', 'Unfinished collection');
        Event::listen(RecordCreated::class, static function (): never {
            throw new RuntimeException('Post-save failure');
        });

        try {
            $form->call('create');
            $this->fail('Expected the post-save failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Post-save failure', $exception->getMessage());
        }

        $this->assertSame(0, Collection::query()->count());
        Livewire::withQueryParams($query)->test(CreateCollection::class)->assertSet('data.name', 'Unfinished collection');
    }

    #[Test]
    public function collection_keeps_the_form_when_filament_halts_and_rolls_back_creation(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Editor', 'email' => 'editor@tgs.test', 'password' => 'password']));

        $query = ['draft' => 'collection-halt'];
        Event::listen(RecordCreated::class, static function (): never {
            throw (new Halt)->rollBackDatabaseTransaction();
        });
        Livewire::withQueryParams($query)->test(CreateCollection::class)
            ->set('data.name', 'Unfinished collection')->call('create')->assertNoRedirect();

        $this->assertSame(0, Collection::query()->count());
        Livewire::withQueryParams($query)->test(CreateCollection::class)->assertSet('data.name', 'Unfinished collection');
    }

    /**
     * @param  class-string<CreateCollection|CreateTgsDemo>  $page
     * @param  class-string<Collection|TgsDemo>  $model
     */
    #[Test]
    #[DataProvider('provisionalCreatePages')]
    public function create_another_opens_a_fresh_draft_after_a_successful_save(string $page, string $model): void
    {
        $this->actingAs(User::query()->create(['name' => 'Editor', 'email' => 'editor@tgs.test', 'password' => 'password']));

        $form = Livewire::withQueryParams(['draft' => 'first-tab'])->test($page);
        $form->call('createAnother')->assertHasFormErrors(['name' => 'required'])->assertNoRedirect()->assertNoJs();
        $first = $model::findProvisional('first-tab');
        $this->assertNotNull($first);

        $form->fillForm(['name' => 'First record'])->call('createAnother')->assertHasNoFormErrors()->assertNoRedirect();
        $redirect = $form->__get('effects')['xjs'][0]['params'][0] ?? null;
        $this->assertIsString($redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $draft = $query['draft'] ?? null;
        $this->assertIsString($draft);
        $this->assertNotSame('first-tab', $draft);
        $this->assertSame($page::getUrl(['draft' => $draft]), $redirect);
        $form->assertJs('url => window.location.replace(url)', $redirect);

        $secondForm = Livewire::withQueryParams($query)->test($page)->assertSuccessful()->assertSet('data.name', null);
        $second = $model::findProvisional($draft);
        $this->assertNotNull($second);
        $this->assertFalse($second->is($first));
        Livewire::withQueryParams($query)->test($page)->assertSuccessful();
        $this->assertTrue($model::findProvisional($draft)?->is($second));

        $secondForm->fillForm(['name' => 'Second record'])->call('create')->assertHasNoFormErrors()->assertRedirect();
        $this->assertSame('First record', $model::query()->findOrFail($first->getKey())->name);
        $this->assertSame('Second record', $model::query()->findOrFail($second->getKey())->name);
        $this->assertSame(2, $model::query()->count());
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
        $demo = TgsDemo::query()->sole();

        $this->get('/admin/tgs-demos/'.$demo->getKey().'/edit')
            ->assertOk()
            ->assertDontSee('tgs::filament.');
    }
}
