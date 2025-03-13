<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

class LayerFactory extends Factory
{
    protected $model = Layer::class;

    public function definition()
    {
        dump('test');
        $a = $this->faker->randomFloat(6, 10, 20);
        $b = $this->faker->randomFloat(6, 10, 20);
        $c = $this->faker->randomFloat(6, 10, 20);
        $d = $this->faker->randomFloat(6, 10, 20);
        // Dummy GeoJSON for a Bbox
        $geojson = json_encode([
            'type' => 'Polygon',
            'coordinates' => [[
                [$a, $b],
                [$b, $c],
                [$c, $d],
                [$d, $a],
            ]],
        ]);

        return [
            'name' => [
                'it' => $this->faker->sentence(3),
                'en' => $this->faker->sentence(3),
            ],
            'configuration' => [
                'test_configuration' => $this->faker->sentence(3),
            ],
            'properties' => [
                'test_property' => $this->faker->sentence(3),
            ],
            'app_id' => App::first()->id,
            'geometry' => \DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
        ];
    }
}
