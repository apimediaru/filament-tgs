<?php

namespace Workbench\Database\Seeders;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Models\Collection;
use ApiMedia\Tgs\Services\BlockTreeAssembler;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\AccordionBuilder;
use ApiMedia\Tgs\Support\BlockTree;
use ApiMedia\Tgs\Support\TabsBuilder;
use Illuminate\Database\Seeder;
use Workbench\App\Models\TgsDemo;
use Workbench\App\Models\User;
use Workbench\App\Tgs\Counter;

class DatabaseSeeder extends Seeder
{
    public const COUNTER_DEMO = 'Демо: счётчик';

    public function run(): void
    {
        User::firstOrCreate(['email' => 'admin@tgs.test'], ['name' => 'TGS Demo', 'password' => 'password']);
        User::firstOrCreate(['email' => 'editor@tgs.test'], ['name' => 'Редактор', 'password' => 'password']);
        $demo = TgsDemo::firstOrCreate(['name' => 'TGS Workbench']);
        if ($demo->wasRecentlyCreated) {
            BlockTree::make()
                ->content('<h2>ТГС — конструктор контента</h2><p>Блоки, вложенность, медиа, история и переиспользование.</p>')
                ->picture('Демонстрационное изображение')->withMedia(__DIR__.'/images/1.jpg')
                ->gallery()->withMedia([__DIR__.'/images/2.jpg', __DIR__.'/images/3.jpg'])
                ->tabs(fn (TabsBuilder $tabs) => $tabs
                    ->tabItem('Описание', fn (BlockTree $tree) => $tree->content('<p>Контент первой вкладки.</p>'))
                    ->tabItem('Пример', fn (BlockTree $tree) => $tree->code('echo "TGS";')))
                ->embedded('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
                ->persistTo($demo, 'content');
        }

        $counterDemo = TgsDemo::firstOrCreate(['name' => self::COUNTER_DEMO]);
        if ($counterDemo->wasRecentlyCreated) {
            app(CanvasPersistence::class)->replaceTree($counterDemo->canvas('content')->hostDescriptor(), [
                ['type' => Counter::TYPE, 'data' => ['count' => 2]],
            ]);
        }

        $library = Canvas::named(TgsNamedCanvas::BlockLibrary);
        if (app(BlockTreeAssembler::class)->assemble($library->hostDescriptor()) === []) {
            BlockTree::make()
                ->content('<h3>Общий блок библиотеки</h3><p>Вставьте ссылкой в два места и измените текст: обе ссылки обновятся. Копия останется независимой.</p>')
                ->picture('Изображение из библиотеки')->withMedia(__DIR__.'/images/1.jpg')
                ->persistToDescriptor($library->hostDescriptor());
        }

        $collection = Collection::firstOrCreate(['name' => 'Демо: готовая секция'], [
            'description' => 'Вставка набором независимых копий; примеры всех контейнеров.',
        ]);
        if ($collection->wasRecentlyCreated) {
            BlockTree::make()
                ->content('<h2>Готовая секция</h2><p>Выберите блоки коллекции в пикере и вставьте набором.</p>')
                ->column(fn (BlockTree $tree) => $tree->content('<p>Одна колонка</p>'))
                ->doubleColumn(
                    fn (BlockTree $tree) => $tree->content('<p>Левая колонка: перенесите блок вправо.</p>'),
                    fn (BlockTree $tree) => $tree->code('echo "Правая колонка";'),
                )
                ->collapse(fn (BlockTree $tree) => $tree->content('<p>Сворачиваемое содержимое</p>'))
                ->popup(fn (BlockTree $tree) => $tree->content('<p>Содержимое окна</p>'))
                ->accordion(fn (AccordionBuilder $items) => $items
                    ->accordionItem('Вопрос', fn (BlockTree $tree) => $tree->content('<p>Ответ</p>')))
                ->persistTo($collection, 'blocks');
        }
    }
}
