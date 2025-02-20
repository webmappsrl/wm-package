<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\App;

/**
 * @template TModel of \Workbench\App\Models\User
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<TModel>
 */
class AppFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TModel>
     */
    protected $model = App::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'sku' => fake()->unique()->word(),
        ];
    }
}
