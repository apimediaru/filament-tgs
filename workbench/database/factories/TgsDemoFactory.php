<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\TgsDemo;

/** @extends Factory<TgsDemo> */
class TgsDemoFactory extends Factory
{
    protected $model = TgsDemo::class;

    public function definition(): array
    {
        return ['name' => $this->faker->words(3, true)];
    }
}
