# ТГС — текстово-графический строитель

Конструктор контента для Laravel 13, Filament 5 и PHP 8.3+. Блоки, канвасы,
библиотека, коллекции, переиспользование, медиа, история, блокировки и корзина.
Namespace `ApiMedia\Tgs`. Вывод — типизированный JSON; фронтенд сайта выбирает потребитель.

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

### Тема выбранной панели

**Новая тема.** Запустите генератор Filament, подставив ID панели из её `->id(...)`.

```bash
php artisan make:filament-theme --panel=admin
```

На вопрос о сборке ответьте «No», чтобы собрать тему после добавления `@source`.
Проверьте, что путь к теме появился в `input` Laravel Vite plugin и в
`->viteTheme(...)` провайдера выбранной панели. Если генератор вывел ручные шаги,
добавьте недостающие настройки.

**Готовая тема.** Откройте CSS-файл, который уже подключён к выбранной панели, и
добавьте директиву в этот файл. В Filament 5.10.3 генератор с флагом
`--no-interaction` принимает «Yes» по умолчанию и может перезаписать существующий
`theme.css` даже без `--force`.

Для `resources/css/filament/admin/theme.css` подходит следующий путь.

```css
@source '../../../../vendor/apimediaru/filament-tgs/{resources/views/**/*.blade.php,src/**/*.php}';
```

Путь считается от CSS-файла. Директива включает Blade-шаблоны и классы,
которые пакет выводит из PHP, например `aspect-video` у превью встроенного видео.
Если вы переопределяете шаблоны ТГС, добавьте также
[источник локальных Blade overrides](#локальные-blade-overrides). Затем соберите тему.

```bash
npm run build
```

Для редактора достаточно JS-ассетов Livewire и Filament Alpine sortable.

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

## Общая секция сайта (с 0.1.2)

Одна регистрация применяется ко всем блокам, использующим стандартную секцию,
во всех панелях, API и CLI, включая CLI без выбранной панели:

```php
TgsPlugin::make()->hosts([Page::class])->commonSettings(\App\Tgs\SiteCommonSettings::class);
```

`app/Tgs/SiteCommonSettings.php`:

```php
namespace App\Tgs;

use ApiMedia\Tgs\Support\TgsCommonSettings;
use App\Data\SiteSectionData;
use Filament\Forms\Components\TextInput;

class SiteCommonSettings extends TgsCommonSettings
{
    public static function dataClass(): string { return SiteSectionData::class; }

    public function heading(array $fields): array
    {
        return [...$fields, TextInput::make('section_icon')->label('Иконка заголовка')];
    }
}
```

`app/Data/SiteSectionData.php`:

```php
namespace App\Data;

use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SiteSectionData extends TgsSectionData
{
    public ?string $section_icon = null;

    public static function fromStored(array $raw): static
    {
        /** @var static $dto */
        $dto = parent::fromStored($raw);
        $dto->section_icon = isset($raw['section_icon']) ? (string) $raw['section_icon'] : null;
        return $dto;
    }
}
```

Сохранённое поле — `Block.data.section_icon`, вывод API — `section.section_icon`.
Форма, storage и DTO — разные контракты: свойство DTO само не читает storage.
Базовый конструктор и стандартные поля копировать не нужно. Второй встроенный блок
подхватит расширение без нового DTO или списка ключей. Для собственного блока используйте
`TgsSectionData::fromStored()` как в рецепте выше. При изменении только подписей,
options, validation или видимости переопределять `dataClass()` не требуется.

Методы `section(array $fields)` и `container(array $fields)` аналогично меняют
настройки секции и её внутренней обёртки. Это не блок-контейнер с дочерним слотом.
Компоненты создаются заново: порядок default → site → override блока → контекст
редактора. Override блока без `parent` полностью заменяет соответствующую группу.
Одинаковая регистрация в нескольких панелях разрешена; разные классы — ошибка,
панель без регистрации использует общую настройку сайта.

Скрытые, отключённые и временно отсутствующие поля сохраняют старые значения при
редактировании; клиент не может записать их через скрытое поле. Видимый `null` очищает
значение. Удаление строки repeater удаляет строку целиком. Если список изменился в другом
сохранении после открытия формы, сохранение отклоняется целиком: откройте блок заново
и повторите изменение. Проверка снимка и запись выполняются под одной блокировкой строки;
изменение другого поля само по себе не создаёт конфликт списка. Новые правила проверяются
при сохранении доступных полей; старые записи автоматически не мигрируют.
Если убрать регистрацию, дополнительные ключи остаются в storage, но стандартный
DTO их не выдаёт. При возврате регистрации они снова доступны.

Запустите `php artisan tgs:check`, `php artisan typescript:transform` и
`php artisan tgs:generate-block-union`. Общий DTO должен иметь собственный
`#[TypeScript]` и попадать в `auto_discover_types` сайта (обычно `app_path()`).
Вложенные DTO также помечайте атрибутом. Тип `section` выбирается из той же регистрации,
отдельная ручная TypeScript-подмена не нужна. Union берёт DTO зарегистрированных блоков,
включая замену одного блока из следующего рецепта.

## Общая проектная категория

Несколько блоков одной категории ссылаются на общее описание сайта. Например,
`app/Tgs/SiteCategories.php` — единственное место для ключа и метаданных:

```php
namespace App\Tgs;

use ApiMedia\Tgs\Support\TgsCategory;

final class SiteCategories
{
    public static function marketing(): TgsCategory
    {
        return new TgsCategory(
            key: 'site_marketing',
            label: 'Маркетинг',
            icon: null,
            order: 40,
        );
    }
}
```

Метод создаёт новый объект без статического кэша. Префикс проекта снижает риск
коллизии со встроенными ключами, но уникальность ключа всё равно проверяет автор сайта.

В двух дескрипторах `App\Tgs\Blocks\Banner` и `App\Tgs\Blocks\Card`
(«Баннер» и «Карточка») добавьте один и тот же метод:

```php
use ApiMedia\Tgs\Support\TgsCategory;
use App\Tgs\SiteCategories;

public function blockCategory(): ?TgsCategory
{
    return SiteCategories::marketing();
}
```

Это дополнение к полноценным дескрипторам `extends TgsBlock`, а не их полная
реализация: каждому нужны собственные `blockType()`, `dataClass()`, подпись и схема.
Их DTO реализуют `ResolvesFromStored`; значение `type` и literal discriminant
должны совпадать с ключом соответствующего блока (см. «Новый блок» выше).
«Карточка» здесь — учебный пример, пакет и стартер не добавляют её в рабочую палитру.

В PanelProvider зарегистрируйте блоки, сохранив остальные настройки плагина:

```php
use ApiMedia\Tgs\TgsPlugin;
use App\Models\Page;
use App\Tgs\Blocks\Banner;
use App\Tgs\Blocks\Card;

TgsPlugin::make()
    ->hosts([Page::class])
    ->blocks([Banner::class, Card::class]);
```

Отдельная `categories()` для общей категории не нужна. `blockCategory()` определяет
принадлежность блока и fallback-метаданные: `TgsCanvas::describeBlock()` использует их,
если в реестре нет категории с таким ключом. При этом `TgsBlockRegistry::category()`
вернёт `null`. Палитра группирует блоки по ключу и сортирует по возрастанию `order`;
подпись и иконку группы берёт из первого блока. Поэтому разные описания одного ключа
в разных дескрипторах дают неоднозначный результат.

`categories()` добавляет или заменяет описание по ключу: последнее определение
выигрывает. Встроенные категории регистрируются до настроек сайта. Зарегистрированное
описание имеет приоритет над fallback дескриптора — это штатный способ осознанно
переопределить метаданные, например встроенной категории.

Если понадобится скрыть общую категорию, добавьте к цепочке плагина:

```php
->withoutCategories([\App\Tgs\SiteCategories::marketing()->key])
```

По умолчанию пример её не скрывает. Скрытие проверяет ключ категории дескриптора
независимо от отдельной регистрации; последующая `categories()` не снимает скрытие.
Новые интерактивные размещения, включая ссылку, копию и вложенные блоки, запрещаются,
но сохранённые блоки продолжают читаться и редактироваться. Это не запрет любых
программных записей: доверенная замена дерева `CanvasPersistence::replaceTree()`
и восстановление прежних размещений из корзины имеют отдельную семантику.

| Изменение | Где править |
| --- | --- |
| Добавить ещё один блок | Его дескриптор и DTO, регистрация `blocks()`, проверки и генерация типов; категорию взять из `SiteCategories` |
| Переименовать категорию, изменить иконку или порядок | Только `SiteCategories::marketing()`, сохраняя технический ключ |
| Скрыть категорию | `withoutCategories()` в конфигурации плагина с ключом общего описания |
| Скрыть только баннер | `withoutBlocks([Banner::blockKey()])`; категория и карточка остаются доступными |

## Скрытие и замена встроенного блока

```php
TgsPlugin::make()
    ->hosts([Page::class])
    ->blocks([Notice::class, SitePicture::class])
    ->withoutBlocks(['code']);
```

Скрытие запрещает **новые интерактивные размещения всеми способами**, включая ссылку/копию и
вложенные деревья; существующие блоки продолжают читаться и редактироваться.
`categories()` задаёт метаданные, `withoutCategories()` скрывает по ключу категории,
как описано выше.
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

## Встраиваемое медиа

Встраиваемое медиа хранит исходный `source` без переписывания. Парсер и производное
поле `embedded_src` допускают только абсолютные HTTP/HTTPS URL, в том числе
неизвестных провайдеров; прочие схемы и относительные адреса дают `null`, превью
не создаёт iframe. Из iframe-кода читается только URL; его остальные атрибуты
не переносятся. Адреса без схемы из шаблонов известных провайдеров приводятся к HTTPS.
Начиная с 0.1.2: старые `source` сохраняются, но небезопасные и
относительные адреса больше не отображаются. Допуск HTTP/HTTPS не является
проверкой доверия к содержимому внешнего сайта.

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

## Локальные Blade overrides

Provider использует `hasViews()`, namespace — `tgs`. Опубликовать шаблоны:

```bash
php artisan vendor:publish --tag=tgs-views
```

Источник: `vendor/apimediaru/filament-tgs/resources/views/`.
Локальная копия: `resources/views/vendor/tgs/`. Например, измените
`resources/views/vendor/tgs/_orphaned-blocks-list.blade.php`, добавив:

```blade
<p class="text-violet-700">Локальная подсказка сайта</p>
```

`view('tgs::_orphaned-blocks-list')` и вложенные `@include('tgs::…')` выберут
локальную копию. Страницы Filament и Livewire также используют этот namespace;
компоненты `x-filament-*` принадлежат Filament и этим тегом не публикуются.
После изменения или удаления override очистите compiled views:

```bash
php artisan view:clear
# Если в deploy используется прогрев:
php artisan view:cache
```

Удаление локального файла возвращает fallback из пакета. К источнику пакета
добавьте источник локальных шаблонов. Для темы
`resources/css/filament/admin/theme.css` это следующий путь.

```css
@source '../../../views/vendor/tgs/**/*.blade.php';
```

После изменений классов выполните `npm run build`. Blade cache не пересобирает CSS. После обновления кода или регистрации настроек
в приложении с Octane выполните `php artisan octane:reload` в рамках deploy.
Обновление Composer не обновляет опубликованные копии: сравнивайте используемые
файлы с новой версией пакета вручную. Не запускайте publish с `--force`, если нужно
сохранить локальные изменения. Лучше оставить только действительно изменённые файлы.
