<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;

class FeatureCollectionFactory extends Factory
{
    protected $model = FeatureCollection::class;

    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'name' => $this->faker->words(3, true),
            'label' => ['it' => $this->faker->sentence(3), 'en' => $this->faker->sentence(3)],
            'enabled' => false,
            'mode' => 'generated',
            'default' => false,
            'clickable' => true,
        ];
    }
}
