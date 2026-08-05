<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcMedia;
use Wm\WmPackage\Models\User;

class UgcMediaFactory extends Factory
{
    protected $model = UgcMedia::class;

    public function definition()
    {
        $geojson = json_encode([
            'type' => 'Point',
            'coordinates' => [$this->faker->longitude(), $this->faker->latitude()],
        ]);

        return [
            'user_id' => User::first()->id ?? User::factory()->create()->id,
            'app_id' => App::first()->id ?? App::factory()->create()->id,
            'name' => $this->faker->word,
            'relative_url' => 'media/images/ugc/'.$this->faker->uuid.'.jpg',
            'properties' => [],
            'geometry' => \DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
        ];
    }
}
