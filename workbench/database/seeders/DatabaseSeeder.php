<?php

namespace Workbench\Database\Seeders;

use ApiMedia\Tgs\Support\BlockTree;
use ApiMedia\Tgs\Support\TabsBuilder;
use Illuminate\Database\Seeder;
use Workbench\App\Models\TgsDemo;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@tgs.test'], ['name' => 'TGS Demo', 'password' => 'password']);
        $demo = TgsDemo::firstOrCreate(['name' => 'TGS Workbench']);
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
}
