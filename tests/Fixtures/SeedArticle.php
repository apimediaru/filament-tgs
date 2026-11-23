<?php

namespace ApiMedia\Tgs\Tests\Fixtures;

use Workbench\App\Models\TgsDemo;

class SeedArticle extends TgsDemo
{
    protected static function newFactory(): SeedArticleFactory
    {
        return SeedArticleFactory::new();
    }

    public static function tgsHostKey(): string
    {
        return 'seed_article';
    }

    public static function canvasGroups(): array
    {
        return ['content', 'summary'];
    }
}
