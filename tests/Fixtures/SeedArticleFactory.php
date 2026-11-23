<?php

namespace ApiMedia\Tgs\Tests\Fixtures;

use ApiMedia\Tgs\Database\Factories\Concerns\HasTgsContent;
use Workbench\App\Models\TgsDemo;
use Workbench\Database\Factories\TgsDemoFactory;

class SeedArticleFactory extends TgsDemoFactory
{
    /** @use HasTgsContent<TgsDemo> */
    use HasTgsContent;

    protected $model = SeedArticle::class;
}
