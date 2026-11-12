# ТГС — текстово-графический строитель

Конструктор контента для Laravel 13, Filament 5 и PHP 8.3+. Блоки, канвасы,
библиотека, коллекции, переиспользование, медиа, история, блокировки и корзина.
Namespace `ApiMedia\Tgs`. Вывод — типизированный JSON; фронтенд сайта выбирает потребитель.

Первый релиз: `0.1.0`. До появления тега и версии на Packagist команды установки
ниже описывают целевой выпуск, а не подтверждают его публикацию.

## Установка в Laravel / Filament

В приложении уже должна быть установлена панель Filament 5. Если её ещё нет:

```bash
composer require filament/filament:'^5.0'
php artisan filament:install --panels
composer require apimediaru/filament-tgs:'^0.1' -W
php artisan vendor:publish --tag=tgs-config
php artisan vendor:publish --provider='Spatie\MediaLibrary\MediaLibraryServiceProvider' --tag=medialibrary-migrations
php artisan migrate
php artisan storage:link
php artisan filament:assets
```

Миграции Media Library публикуйте только если таблица `media` ещё не принадлежит
вашему приложению. ТГС создаёт восемь собственных `tgs_*` таблиц. Provider пакета
обнаруживается Composer автоматически. Настройте доступный файловый диск и обработчик
очереди, если используете асинхронные конверсии.

В методе `panel()` вашего `PanelProvider`:

```php
use ApiMedia\Tgs\TgsPlugin;
use App\Models\Page;

return $panel->plugin(TgsPlugin::make()->hosts([Page::class]));
```

В CSS темы Filament, например `resources/css/filament/admin/theme.css`, добавьте:

```css
@source '../../../../vendor/apimediaru/filament-tgs/resources/views/**/*.blade.php';
```

Путь считается от CSS-файла. Подключите тему в PanelProvider через `viteTheme()`
и пересоберите её (`npm run build`). Редактор использует Livewire и Filament Alpine
sortable; самостоятельной JS-сборки пакета у потребителя нет.

## Минимальный хост и поле редактора

Пример для существующей таблицы `pages` с числовым `id` и строкой `name`:

```php
namespace App\Models;

use ApiMedia\Tgs\Models\Concerns\HasCanvases;
use ApiMedia\Tgs\Models\Concerns\HasCanvasContent;
use ApiMedia\Tgs\Models\Contracts\HasCanvasContent as ReadsCanvasContent;
use ApiMedia\Tgs\Support\Contracts\TgsHost;
use Illuminate\Database\Eloquent\Model;

class Page extends Model implements TgsHost, ReadsCanvasContent
{
    use HasCanvases, HasCanvasContent;

    protected $fillable = ['name'];

    public static function tgsHostKey(): string { return 'page'; }
    public static function canvasGroups(): array { return ['content']; }
    public function tgsLabel(): string { return $this->name; }
    public function tgsEditUrl(): ?string { return null; }
}
```

Замените `null` в `tgsEditUrl()` на URL редактирования вашего ресурса, если нужна
навигация из «Где используется». На **edit-странице сохранённой записи**:

```php
use ApiMedia\Tgs\Filament\Components\TgsCanvasField;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

public static function form(Schema $schema): Schema
{
    return $schema->columns(1)->components([
        TextInput::make('name')->required(),
        TgsCanvasField::for('content')->columnSpanFull(),
    ]);
}
```

`$page->getContent()` возвращает DTO; `$page->getTextContent()` — текст.
Для автосохранения **до создания записи** нужны `HasProvisionalRecord`,
`HasProvisionalRecordTrait`, nullable timestamp `provisional_at` и create-страница
с `HandlesProvisionalCreate`. Полный рабочий пример —
[модель Workbench](https://github.com/apimediaru/filament-tgs/blob/main/workbench/app/Models/TgsDemo.php)
и [CreateTgsDemo](https://github.com/apimediaru/filament-tgs/blob/main/workbench/app/Filament/Resources/TgsDemoResource/Pages/CreateTgsDemo.php).

Для embedded-канвасов владелец сохраняет **полный список** через
`Canvas::claim($ids, $ownerKey, $cacheKeys)` в одной транзакции со своими данными.
Поле само не знает полного списка и не делает клейм автоматически. У канваса один
владелец; чужой клейм отклоняется атомарно. Освобождение даёт 24 часа до очистки.

## Новый блок

DTO читает сохранённую форму через `fromStored`, а не `Data::from`:

```php
namespace App\Data;

use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class NoticeData extends Data implements ResolvesFromStored
{
    #[LiteralTypeScriptType('"notice"')]
    public string $type = 'notice';

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        public ?string $message,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static((string) ($raw['id'] ?? ''), TgsSectionData::fromStored($raw), $raw['message'] ?? null);
    }
}
```

```php
namespace App\Tgs;

use ApiMedia\Tgs\Support\TgsBlock;
use App\Data\NoticeData;
use Filament\Forms\Components\TextInput;

class Notice extends TgsBlock
{
    public static function blockType(): string { return 'notice'; }
    public static function dataClass(): ?string { return NoticeData::class; }
    public function getBlockLabel(): string { return 'Объявление'; }
    public function schema(): array { return [TextInput::make('message')->required()]; }
}
```

Регистрация: `TgsPlugin::make()->hosts([Page::class])->blocks([Notice::class])`.
После добавления проверьте `php artisan tgs:check` и генерацию типов. Nullable-поля
присутствуют в выводе даже без значения. Неизвестный тип читается как `UnknownBlockData`.

## Скрытие и замена встроенного блока

```php
TgsPlugin::make()
    ->hosts([Page::class])
    ->blocks([Notice::class, SitePicture::class])
    ->withoutBlocks(['code']);
```

Скрытие запрещает **новые размещения всеми способами**, включая ссылку/копию и
вложенные деревья; существующие блоки продолжают читаться и редактироваться.
`categories()` / `withoutCategories()` управляют категориями по тому же принципу.
Отключение общих вкладок дескриптора не удаляет сохранённые значения.

Замена сохраняет ключ `picture`; DTO обязательно наследует исходный:

```php
namespace App\Data;

use ApiMedia\Tgs\Data\Blocks\PictureData;
use Illuminate\Database\Eloquent\Model;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SitePictureData extends PictureData
{
    public ?string $credit_note = null;

    public static function fromStored(array $raw, Model $model): static
    {
        $dto = parent::fromStored($raw, $model);
        $dto->credit_note = $raw['credit_note'] ?? null;
        return $dto;
    }
}
```

```php
namespace App\Tgs;

use ApiMedia\Tgs\Blocks\Picture;
use App\Data\SitePictureData;
use Filament\Forms\Components\TextInput;

class SitePicture extends Picture
{
    public static function dataClass(): ?string { return SitePictureData::class; }
    public function schema(): array
    {
        return [...parent::schema(), TextInput::make('credit_note')];
    }
}
```

`tgs:check` проверяет структуру формы/DTO. Отдельно проверьте сохранение и чтение
`credit_note`: наличие свойства само по себе не гарантирует заполнение в `fromStored`.

## Медиа-конверсии

```php
use ApiMedia\Tgs\Models\Block;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

TgsPlugin::make()->mediaConversions(function (Block $block, ?Media $media): void {
    $width = $block->type === 'gallery' ? 800 : 1600;
    $block->addMediaConversion('banner')->width($width)->nonQueued();
});
```

Callback **заменяет** набор стандартных конверсий блока. Все доступные варианты
выходят в `image.conversions`, например `image.conversions.banner.url`; прежние
поля `original/tiny/thumb/medium/large` сохранены. Для production можно использовать
очередь вместо `nonQueued()`. Конверсии других моделей сайта не меняются.

## Права

В `boot()` провайдера приложения, где `User` — ваша модель пользователя:

```php
use Illuminate\Support\Facades\Gate;
use App\Models\User;

Gate::define('tgs.access', fn (User $user): bool => $user->can('edit_content'));
Gate::define('tgs.manageLibrary', fn (User $user): bool => $user->can('edit_content'));
Gate::define('tgs.manageLocks', fn (User $user): bool => $user->can('manage_content'));
Gate::define('tgs.deleteEverywhere', fn (User $user): bool => $user->can('manage_content'));
```

`edit_content` / `manage_content` — способности вашего сайта, определите их своей
системой ролей. Альтернатива: `->permissions(['tgs.access' => 'edit_content'])`.
По умолчанию `tgs.access` разрешён авторизованному пользователю, три остальных —
запрещены. Явный Gate сайта имеет приоритет. Зависимости от Spatie Permission нет.

## TypeScript

Пакет не поставляет готовые `.d.ts`; типы генерирует сайт:

```bash
php artisan vendor:publish --provider='Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerServiceProvider' --tag=typescript-transformer-config
```

В `config/typescript-transformer.php`:

```php
'auto_discover_types' => [app_path(), base_path('vendor/apimediaru/filament-tgs/src')],
'collectors' => [Spatie\TypeScriptTransformer\Collectors\DefaultCollector::class],
'transformers' => [
    Spatie\TypeScriptTransformer\Transformers\EnumTransformer::class,
    Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer::class,
    Spatie\LaravelTypeScriptTransformer\Transformers\DtoTransformer::class,
],
'output_file' => resource_path('types/laravel.d.ts'),
'writer' => Spatie\TypeScriptTransformer\Writers\TypeDefinitionWriter::class,
'formatter' => null,
```

```bash
php artisan typescript:transform
php artisan tgs:generate-block-union
php artisan tgs:check
```

Union `ApiMedia.Tgs.Data.Block` записывается в `resources/types/tgs-blocks.d.ts`.
Генерируйте оба файла после изменения DTO или набора зарегистрированных блоков.

## Очистка и существующая схема

В `routes/console.php` приложения:

```php
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Support\Facades\Schedule;

Schedule::command('tgs:gc-block-trash')->hourly();
Schedule::command('tgs:gc-embedded-canvases')->hourly();
Schedule::command('model:prune', ['--model' => [TgsModels::block(), TgsModels::collection()]])->hourly();
```

Запустите Laravel scheduler. Стандартный `model:prune` без `--model` сканирует только
модели приложения, поэтому пакетные модели перечислены явно. Сохраните также очистку
моделей собственного сайта. Корзина хранится 60 дней, черновики/незаклеймленные
embedded-канвасы — не менее 24 часов; настройки в `config/tgs.php`.

Стартер сохраняет исторические миграции и `install_schema=false`: это отключает
только initial create, будущие upgrades загружаются всегда. Для совместимой готовой
схемы есть `php artisan tgs:adopt-existing`: проверяет таблицы/колонки/ограничения
и отмечает baseline. Команда не переименовывает старые таблицы или FQCN. Не запускайте
две цепочки создания таблиц на одной БД. Авторские ID числовые nullable, без FK на
пользователей; сайт с UUID-авторами должен подготовить совместимые колонки.

## Workbench: попробовать возможности

Workbench находится в Git checkout, а не в Composer dist потребителя:

```bash
git clone https://github.com/apimediaru/filament-tgs.git
cd filament-tgs
composer install
npm ci
npm run build
vendor/bin/testbench workbench:build
APP_URL=http://localhost:10010 vendor/bin/testbench serve --host=0.0.0.0 --port=10010
```

`workbench:build` **пересоздаёт SQLite демо**; используйте только для первоначальной
установки или намеренного сброса. Для добавления примеров в существующую витрину:

```bash
vendor/bin/testbench db:seed --class='Workbench\Database\Seeders\DatabaseSeeder'
```

Вход `/admin/login`: `admin@tgs.test` / `password`; редактор `editor@tgs.test` /
`password` не может управлять блокировками и удалять везде. Это локальные демо-аккаунты.
Страница «Как проверить ТГС» ведёт в библиотеку, коллекции, демо и JSON и описывает
сценарии DnD, ссылки/копии/токены, историю, корзину, медиа и права. Все 11 публичных
типов доступны через демо и коллекцию, включая вложенные вкладки/аккордеон.
Историю и корзину наполняйте изменениями экспериментальной записи.

В Docker стартера используйте `make tgs-test`, `make tgs-analyse`, `make tgs-lint`,
`make tgs-workbench-serve`; PHP/Composer/Node запускайте в app-контейнере.

## Разработка и проверки

```bash
composer test
composer analyse
composer lint
```

CI: PHP 8.3/8.4 × SQLite/PostgreSQL, плюс сборка темы. PostgreSQL задаётся через
`TGS_TEST_DRIVER=pgsql`, `TGS_TEST_HOST`, `TGS_TEST_PORT`, `TGS_TEST_DATABASE`,
`TGS_TEST_USERNAME`, `TGS_TEST_PASSWORD`. Имя отдельной тестовой БД должно содержать
сегмент `test`. Никогда не указывайте рабочую БД.

Для совместной разработки сайт может временно подключить соседний checkout:

```json
{"repositories": [{"type": "path", "url": "../filament-tgs", "options": {"symlink": true, "versions": {"apimediaru/filament-tgs": "dev-main"}}}], "require": {"apimediaru/filament-tgs": "@dev"}}
```

Это не установка опубликованной версии. В 0.x минорный релиз может менять API;
патч-релиз сохраняет публичные контракты. [Изменения](CHANGELOG.md) · [Словарь](GLOSSARY.md).
