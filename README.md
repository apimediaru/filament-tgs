# ТГС — текстово-графический строитель

Конструктор контента для Laravel 13, Filament 5 и PHP 8.3+. Блоки, канвасы,
переиспользование, медиа, история и корзина. Namespace: `ApiMedia\Tgs`.
Рабочая версия B8 до первого релиза; версия `0.1.0` ещё не опубликована.

## Подключение из соседнего репозитория

В `composer.json` приложения:

```json
{
  "repositories": [{
    "type": "path",
    "url": "../filament-tgs",
    "options": {"symlink": true, "versions": {"apimediaru/filament-tgs": "dev-main"}}
  }],
  "require": {"apimediaru/filament-tgs": "@dev"}
}
```

В Docker оба каталога должны быть доступны по этим относительным путям.
Стартер монтирует пакет в `/var/www/filament-tgs`, приложение — `/var/www/html`.
После `composer update apimediaru/filament-tgs` provider обнаруживается автоматически.
В `PanelProvider` добавьте плагин:

```php
use ApiMedia\Tgs\TgsPlugin;

$panel->plugin(
    TgsPlugin::make()->hosts([News::class, Article::class, Page::class])
);
```

Хост реализует `Support\Contracts\TgsHost`: `tgsHostKey`, `tgsLabel`, `tgsEditUrl`
и контракт канвасов. Минимальный пример модели и ресурса — `workbench/app`.
Для встроенного поля без сохранённой модели предусмотрены отдельные canvas claims;
владение и время жизни канваса не следует подменять ролями пользователя.

Добавьте в CSS темы Filament источник классов:

```css
@source '../../../../vendor/apimediaru/filament-tgs/resources/views/**/*.blade.php';
```

Путь зависит от расположения CSS файла сайта. JS-механика использует Livewire и
встроенный Filament Alpine sortable. Пересоберите тему сайта после установки.

## База и модели

Новая установка: `php artisan migrate` создаёт восемь таблиц `tgs_*`.
`php artisan vendor:publish --tag=tgs-config` публикует конфиг.
Модели авторов/медиа и сроки очистки можно настроить в `config/tgs.php`.
По умолчанию автор берётся из активного auth provider; поля идентификаторов авторов
nullable числовые, без FK на сайт. Для UUID авторов сайт задаёт совместимые колонки.

У существующего стартера сохранена историческая цепочка, поэтому `install_schema=false`.
Это отключает только initial create; будущие package upgrades загружаются всегда.
Стартерная миграция B8 заменяет только сохранённые model FQCN и принимает baseline.
Для другой существующей совместимой схемы есть `php artisan tgs:adopt-existing`:
команда проверяет наличие колонок/ограничений, затем идемпотентно отмечает initial migration.
Она сама не переименовывает старые таблицы или классы. Блоковый JSON не заменяется строковым поиском.

## Расширение и полномочия

```php
TgsPlugin::make()
    ->hosts([News::class])
    ->blocks([SitePicture::class])
    ->withoutBlocks(['video'])
    ->withoutCategories(['legacy'])
    ->permissions(['tgs.access' => 'edit_content']);
```

`blocks()` принимает классы дескрипторов; совпадающий ключ заменяет встроенный блок.
DTO замены обязан наследовать исходный DTO; `php artisan tgs:check` проверяет
соответствие схемы/DTO и допустимость структуры. `withoutBlocks` скрывает блок в
picker, чтение старых данных остаётся доступным. Для категорий доступны
`categories()` и `withoutCategories()`.

Медиа-конверсии задаются callback `mediaConversions(function (Block $block, ?Media $media): void { ... })`.
В нём регистрируются конверсии блока; сайт выбирает очередь и размеры.
Состояние реестров запросов — scoped, boot-конфигурация плагина переживает новый scope.

Права назначает сайт через Gate или `permissions()`; зависимость от системы ролей не нужна.
`tgs.access` по умолчанию разрешён авторизованному пользователю.
`tgs.manageLibrary`, `tgs.manageLocks`, `tgs.deleteEverywhere` по умолчанию запрещены.
Явно определённый Gate сайта имеет приоритет. Workbench отдельно разрешает эти действия
своему демо-пользователю; его provider не устанавливается в приложение потребителя.

## DTO и TypeScript

Пакет не поставляет готовые `.d.ts`. Сайт генерирует их своим трансформером.
Добавьте в `config/typescript-transformer.php`:

```php
'auto_discover_types' => [app_path(), base_path('vendor/apimediaru/filament-tgs/src')],
```

В `transformers` необходимы `Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer`
и совместимый enum/DTO transformer. Затем:

```bash
php artisan typescript:transform
php artisan tgs:generate-block-union
```

Второй файл — `resources/types/tgs-blocks.d.ts`, union `ApiMedia.Tgs.Data.Block`.
Неизвестные типы читаются как `UnknownBlockData`; форма и DTO должны расширяться вместе.

## Workbench

Самостоятельное демо на Testbench, без bootstrap или БД стартера:

```bash
composer install
npm ci
npm run build
vendor/bin/testbench workbench:build
APP_URL=http://localhost:10010 vendor/bin/testbench serve --host=0.0.0.0 --port=10010
```

`workbench:build` пересоздаёт только SQLite демо Testbench; не запускайте его с
подставленным production-подключением. Вход `/admin/login`: `admin@tgs.test` / `password`.
Демо содержит изображения, галерею, вложенные вкладки и страницу просмотра JSON.

В Docker стартера доступны `make tgs-test`, `make tgs-analyse`, `make tgs-lint`,
`make tgs-workbench-build`, `make tgs-workbench-serve`. Порт `10010` опубликован
только на loopback; `DOCKER_PORT_TGS_WORKBENCH` меняет внешний порт.

## Проверки

```bash
composer test       # независимая SQLite :memory:
composer analyse
composer lint
```

PostgreSQL: задайте `TGS_TEST_DRIVER=pgsql` и `TGS_TEST_HOST`, `TGS_TEST_PORT`,
`TGS_TEST_DATABASE`, `TGS_TEST_USERNAME`, `TGS_TEST_PASSWORD`, затем `composer test`.
Имя БД должно содержать отдельный сегмент `test`; используйте выделенную тестовую БД.
CI содержит PHP 8.3/8.4 × SQLite/PostgreSQL и сборку темы. Локально проверены PHP 8.4
и обе БД; удалённая CI-матрица будет выполнена после публикации репозитория.

[Словарь](GLOSSARY.md) · [Контекст](CONTEXT.md) · [ADR](docs/adrs/README.md).
