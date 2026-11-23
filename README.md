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

### Восстановление формы создания

При повторном открытии того же `?draft=…` в той же сессии `HandlesProvisionalCreate`
восстанавливает канвасы и введённые значения обычных полей. Текст отправляется после
паузы при вводе. Списки и переключатели сохраняются при изменении. При обновлении
страницы вернётся последнее значение, которое сервер успел сохранить. Временные
файлы из обычных полей нужно выбрать заново. Уже сохранённые файлы внутри блоков
восстанавливаются вместе с блоками.

Форма использует серверную сессию для доступа к черновику и постоянный кэш Laravel
для значений его полей. Каждый черновик хранится под своим ключом. «Отмена» возвращает
в список. Прежний адрес позволяет продолжить работу до очистки черновика. Ошибка
сохранения оставляет черновик доступным. После успешного «Создать» действуют обычные
правила видимости записи, а «Создать ещё» открывает чистую форму.

## Фабрики и сидеры

Добавьте трейт `HasTgsContent` в Laravel-фабрику модели, у которой есть ТГС-канвасы.
Например, внутри существующего класса `ArticleFactory`:

```php
use \ApiMedia\Tgs\Database\Factories\Concerns\HasTgsContent;
```

Фабрика заполняет только канвасы, указанные в `withTgs()`. В примере модель объявляет
группы `content` и `summary` в `canvasGroups()`.

```php
use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Blocks\DoubleColumn;
use ApiMedia\Tgs\Blocks\Gallery;
use ApiMedia\Tgs\Blocks\TabItem;
use ApiMedia\Tgs\Blocks\Tabs;
use App\Models\Article;
use App\Tgs\Blocks\Banner;

$articles = Article::factory()
    ->count(5)
    ->withTgs('summary', Content::seed('<p>Кратко</p>'))
    ->withTgs('content',
        Content::seed()->count(2),
        Banner::seed(title: 'Летняя акция'),
        Tabs::seed(TabItem::seed('Обзор', DoubleColumn::seed(
            left: Content::seed('<p>Слева</p>'),
            right: Gallery::seed($firstImagePath, $secondImagePath),
        ))),
    )
    ->withTgs('content', Content::seed('<p>В конце</p>'))
    ->create();
```

`seed()` возвращает рецепт блока (`BlockSpec`). Каждый вызов `create()` создаёт из
рецепта новые блоки и заново вычисляет данные Faker для каждого экземпляра.
Второй `withTgs('content', ...)` добавляет блоки в конец списка для этой статьи.
`withTgs('content')` и `Content::seed()->count(0)` пропускают заполнение.
`make()` работает как обычная фабрика Laravel и откладывает весь рецепт ТГС до `create()`.

Пакет сверяет ключи групп с моделью, а типы и дочерние слоты с текущими дескрипторами.
Неизвестный тип, скрытый блок, опечатка в слоте или запрет на размещение дают ошибку
до сохранения дерева. Используйте соединение Laravel по умолчанию для модели и таблиц ТГС.

Фабрика сохраняет статью, её блоки и медиа в одной транзакции БД. При откате
пакет убирает файлы, которые успел записать для этой статьи, и сохраняет исходные
файлы. Уже созданные статьи из той же пачки остаются. Внешняя транзакция Laravel
может откатить их вместе. Исключение из обработчика после коммита сохраняет
и записи, и их файлы.

### Рецепт для блока приложения

Добавьте метод в зарегистрированный дескриптор своего блока.
В этом примере Banner уже умеет показывать картинку через один UUID поля.

```php
use ApiMedia\Tgs\Support\BlockSpec;
use App\Enums\BannerAppearance;

public static function seed(
    string $title,
    ?string $image = null,
    BannerAppearance $appearance = BannerAppearance::Calm,
): BlockSpec {
    return BlockSpec::of(
        static::blockKey(),
        ['title' => $title, 'appearance' => $appearance->value],
        files: $image === null ? [] : [$image],
    );
}

public static function contentMediaDisk(): ?string
{
    return 'public';
}
```

В загрузчике используйте `->disk(static::contentMediaDisk())->visibility('public')`.
Этот же диск применяет фабрика. Без явного диска фабрика использует настройки
коллекции и Spatie Media Library. Для блока с медиа пакет создаёт один `data.uuid`.
Все файлы галереи с таким UUID принадлежат одному полю и сохраняются в заданном
порядке. Передавайте пути к локальным файлам, доступным внутри контейнера.
См. [форму Picture](src/Blocks/Picture.php) и [чтение изображения](src/Data/Blocks/PictureData.php).

Для своего блока с полем JavaScript можно тем же способом вернуть
`BlockSpec::of(static::blockKey(), ['count' => $initial])` из `Counter::seed(int $initial = 0)`.
У `BlockSpec::of()` аргумент `data` также принимает функцию, если данные нужно
вычислить заново при каждом создании. Вложенное содержимое передавайте в `slots`.

### Импорт готового дерева

В этом примере `Article` — модель приложения с двумя группами из `canvasGroups()`,
`['content', 'summary']`. Основной текст идёт первым, поскольку `getContent()`
без аргумента читает первую группу. Используйте ключи, объявленные вашей моделью.
Обычный `Article::factory()->create()` создаёт оба канваса пустыми. Для заполнения
одного канваса вызовите только соответствующий `replaceTree()`.

```php
use ApiMedia\Tgs\Database\Factories\BlockFactory;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\BlockTree;
use ApiMedia\Tgs\Support\TabsBuilder;
use App\Enums\BannerAppearance;
use App\Models\Article;
use App\Tgs\Blocks\Banner;
use Illuminate\Support\Facades\DB;

$article = DB::transaction(function (): Article {
    $article = Article::factory()->create();
    $writer = app(CanvasPersistence::class);
    $node = static fn (Block $block): array => [
        'type' => $block->type,
        'data' => ['id' => $block->id] + $block->data,
    ];

    $summary = BlockFactory::new()->content(['content' => '<p>Кратко</p>'])->make();
    $writer->replaceTree($article->canvasDescriptor('summary'), [$node($summary)]);

    $main = BlockTree::make()->tabs(fn (TabsBuilder $tabs) => $tabs
        ->tabItem('Обзор', fn (BlockTree $tab) => $tab->doubleColumn(
            fn (BlockTree $left) => $left->content('<p>Слева</p>'),
            fn (BlockTree $right) => $right->content('<p>Справа</p>'),
        ))
        ->tabItem('Детали', fn (BlockTree $tab) => $tab->content('<p>Подробности</p>')));

    // Banner зарегистрирован приложением через TgsPlugin::blocks([Banner::class]).
    $banner = BlockFactory::new()->state([
        'type' => Banner::blockKey(),
        'data' => [
            'title' => 'Летняя акция',
            'description' => 'Товары из сезонной подборки.',
            'link' => 'https://example.com/sale',
            'appearance' => BannerAppearance::Accent->value,
        ],
    ])->make();

    $writer->replaceTree($article->canvasDescriptor('content'), [
        ...$main->toArray(),
        $node($banner),
    ]);

    return $article;
});
```

`Banner` и `BannerAppearance` в примере принадлежат приложению-стартеру. Для своего
блока передайте ключ его дескриптора и данные по схеме этого блока. Регистрация нужна
для чтения через DTO сайта. Доверенный `replaceTree()` принимает и старые снимки
с неизвестными ключами. Они читаются как нераспознанные блоки.

`BlockFactory::make()` собирает блок в памяти. С `count(3)` он вернёт коллекцию,
из которой можно собрать три узла тем же преобразованием `$node`. Для каждого нового
канваса создавайте блоки и дерево заново. Если достаточно встроенных блоков, можно
вызвать `$main->persistTo($article, 'content')`, который сам передаёт снимок в
`replaceTree()`. Пустое дерево `persistTo()` отклоняет. Явный `replaceTree($descriptor, [])`
очищает размещения целевого канваса.

`getContent('summary')` отдаёт список с текстовым блоком. В `getContent('content')`
вкладки раскрываются в `items`, а текст внутри колонок — в `left` и `right`.
Добавьте эти поля в DTO статьи, если оба канваса должны быть доступны в API.
Транзакция вокруг создания статьи и обоих вызовов отменяет все записи в БД при сбое.

Сокращённый JSON с обоими канвасами:

```json
{
  "summary": [{"type": "content", "content": "<p>Кратко</p>"}],
  "content": [
    {"type": "tabs", "items": [
      {"title": "Обзор", "content": [{"type": "double_column",
        "left": [{"type": "content", "content": "<p>Слева</p>"}],
        "right": [{"type": "content", "content": "<p>Справа</p>"}]}]},
      {"title": "Детали", "content": [{"type": "content", "content": "<p>Подробности</p>"}]}
    ]},
    {"type": "site_banner", "title": "Летняя акция", "appearance": "accent"}
  ]
}
```

### Ручное прикрепление медиа после импорта

Если Banner сайта содержит изображение, добавьте в его схему
`static::mediaUuidHiddenField()` и настройте загрузчик для этого поля по образцу
[Picture](src/Blocks/Picture.php). Для изображения, доступного посетителям сайта,
явно задайте `->disk('public')->visibility('public')`. Загрузчик использует
уже созданную запись блока. Свойство медиа `custom_properties.uuid` связывает
прикреплённый файл с `data.uuid` блока.

Фабрика статьи может выполнить такое прикрепление в `afterCreating()`.
Заранее задайте ID блока и UUID поля, чтобы найти запись после импорта:

```php
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\TgsModels;
use App\Models\Article;
use App\Tgs\Blocks\Banner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// $bannerData — поля Banner сайта, а $imagePath — путь к файлу в app-контейнере.
$factory = Article::factory()->afterCreating(
    function (Article $article) use ($bannerData, $imagePath): void {
        $id = (string) Str::ulid();
        $uuid = (string) Str::uuid();

        app(CanvasPersistence::class)->replaceTree($article->canvasDescriptor('content'), [
            ['type' => Banner::blockKey(), 'data' => ['id' => $id, 'uuid' => $uuid] + $bannerData],
        ]);

        $block = TgsModels::block()::query()->findOrFail($id);
        $block->addMedia($imagePath)
            ->preservingOriginal()
            ->withCustomProperties(['uuid' => $uuid])
            ->toMediaCollection($block::getContentMediaCollectionName(), 'public');
    },
);

$article = DB::transaction(fn () => $factory->create());
```

В `BannerData::fromStored()` найдите запись блока по сохранённому `id` и медиа через
`$block->contentMediaWithUuid($uuid)->first()`. Передайте найденный файл в
`TgsImageData::fromMedia()`. Транзакция откатывает строки БД. Уже записанным файлам
при сбое может понадобиться отдельная очистка.

## Кэш публичной выдачи

Сайт выбирает кэшируемые ответы, TTL и ключи. ТГС находит все затронутые хосты,
включая вложенные и переиспользованные блоки и их медиа. Хост-модель объявляет
ключи через `InvalidatesCache`, именованный канвас использует
`tgs.named_canvas_cache_keys`, встроенный получает ключи из `Canvas::claim()`.

Если сайт использует стандартный кэш Laravel, достаточно обычного `TgsPlugin::make()`
с существующей регистрацией хостов. Пакет запоминает ключи до удаления хоста,
добавляет финальные ключи сохранившегося хоста и сбрасывает их после commit.
Rollback отбрасывает вызов. Прямое событие вне транзакции обрабатывается сразу.

Поэкземплярный `delete()` канваса или модели с `HasCanvases` выполняет каскад
и удаляет строку в одной транзакции на общем соединении ТГС. Внешняя транзакция
откладывает сброс до своего commit. Отказ модельного события откатывает
изменения в БД и отменяет отложенный сброс.
Используйте удаление с включёнными модельными событиями для админки, pruning
и других путей удаления. `deleteQuietly()` и массовое удаление через query
builder обходят этот каскад. Чтение с новым снимком после сброса на основной
БД видит результат commit. Если сайт пишет в кэш данные, прочитанные
до commit, согласование с такой отложенной записью остаётся за сайтом.

Для выдачи, которую сайт кэширует в отдельно выбранном хранилище Redis, можно
подключить свой обработчик через `TgsPlugin::make()->siteCache(SiteCache::class)`:

```php
namespace App\Tgs;

use ApiMedia\Tgs\Support\Contracts\SiteCacheInvalidator;
use ApiMedia\Tgs\Support\HostDescriptor;
use Illuminate\Support\Facades\Cache;

final class SiteCache implements SiteCacheInvalidator
{
    public function invalidate(HostDescriptor $host, array $keys): void
    {
        $cache = Cache::store('redis');
        foreach ($keys as $key) {
            $cache->forget($key);
        }
    }
}
```

Добавьте `->siteCache(SiteCache::class)` к существующей регистрации плагина.
Способ сброса действует на всё приложение, включая API, CLI и воркеры конверсии.
Панели без явной настройки наследуют общий выбор, разные явные настройки дают
ошибку при загрузке. Обработчик получает непустой список уникальных ключей на
одно событие хоста. Разные события могут содержать один и тот же ключ.

Для сайта без публичного кэша используйте `->siteCache(false)`. Во всех режимах
синхронный сигнал `TgsContentChanged` сохраняется. Стандартный обработчик
отправляет `TgsCacheInvalidated` после каждого вызова `forget`. Свой обработчик
сам отправляет уведомление при необходимости, например после завершения задачи
очистки CDN. В режиме отключения события сброса нет. Сбор метаданных медиа и
внутренние кэши ТГС работают независимо от этого выбора.

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

### Проверка в Docker

1. В PHP-контейнере приложения запустите `composer check-platform-reqs` и `php --ri gd`.
   Для настройки `IMAGE_DRIVER=gd` нужен GD с поддержкой формата загружаемого файла.
   Spatie Media Library также требует `exif` и `fileinfo`. Версию PHP определяет lock
   сайта. Например, стартер с Spatie Image 3.9.9 использует PHP 8.4.
2. На локальном стенде задайте `QUEUE_CONVERSIONS_BY_DEFAULT=false` и перезагрузите
   конфигурацию приложения. Если используется Octane, перезапустите его воркеры.
   Для публичного локального диска настройте `APP_URL` и раздачу `/storage`
   через `php artisan storage:link` или alias веб-сервера.
3. Загрузите новое изображение на блок со стандартными конверсиями. Получите JSON блока
   из API. `image.conversions` должен содержать `original`, `tiny`, `thumb`, `medium`
   и `large`. Откройте каждый URL и сверьте `width` и `height` с результатом
   `getimagesize()` скачанного файла.

При включённой очереди новые конверсии появляются после выполнения задачи. Запустите
воркер на тех же соединении и очереди, которые указаны в настройках Media Library.
У воркера должен быть доступ к дискам и выбранному PHP-драйверу. Для закрытого диска
используйте подписанный URL. Обычная ссылка на него может вернуть HTTP 403.

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

`KnownBlock` содержит типы из того же реестра. Полный `Block` включает также
нераспознанный блок с исходным `type` и полем `data` в форме объекта или списка.
Даже известный `type` может иметь несовместимые данные, поэтому перед чтением
поля проверьте его наличие:

```ts
function readText(block: ApiMedia.Tgs.Data.Block): string | null {
  if (block.type === 'content' && 'content' in block) return block.content;
  if (block.type === 'site_banner' && 'title' in block) return block.title;
  return null;
}
```

Вторая ветка относится к сайту, который зарегистрировал Banner стартера.

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

Удаление локального файла возвращает fallback из пакета. Для темы
`resources/css/filament/admin/theme.css` нужны оба источника Tailwind:

```css
@source '../../../../vendor/apimediaru/filament-tgs/resources/views/**/*.blade.php';
@source '../../../views/vendor/tgs/**/*.blade.php';
```

Первый собирает классы поставляемых шаблонов, второй — новые классы локальных копий.
После изменений классов выполните `npm run build`. Blade cache не пересобирает CSS. После обновления кода или регистрации настроек
в приложении с Octane выполните `php artisan octane:reload` в рамках deploy.
Обновление Composer не обновляет опубликованные копии: сравнивайте используемые
файлы с новой версией пакета вручную. Не запускайте publish с `--force`, если нужно
сохранить локальные изменения. Лучше оставить только действительно изменённые файлы.

## Руководство заказчика в админке

Пакет поставляет главы ru/en в `resources/docs/` и объявляет их в composer.json.
Для отображения установите `apimediaru/filament-documentation` и подключите
`DocumentationPlugin::make()` к своей панели. Руководство ТГС обнаруживается
автоматически, возле канваса появляется ссылка «Инструкция».

ТГС работает самостоятельно при отсутствии просмотрщика. При отключении
источника `tgs` в просмотрщике ссылка помощи скрывается. Главы соответствуют
установленному релизу пакета и обновляются вместе с ним.
