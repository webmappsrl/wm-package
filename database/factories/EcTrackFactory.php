<?php

namespace Wm\WmPackage\Database\Factories;

use DB;
use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

class EcTrackFactory extends Factory
{
    protected $model = EcTrack::class;

    public function definition()
    {
        // Dummy GeoJSON for a MultiLineString
        $geojson = json_encode([
            'type' => 'MultiLineString',
            'coordinates' => [
                [
                    [
                        $this->faker->randomFloat(6, 10, 20),
                        $this->faker->randomFloat(6, 40, 50),
                        $this->faker->randomFloat(2, 0, 1000),
                    ],
                    [
                        $this->faker->randomFloat(6, 10, 20),
                        $this->faker->randomFloat(6, 40, 50),
                        $this->faker->randomFloat(2, 0, 1000),
                    ],
                    [
                        $this->faker->randomFloat(6, 10, 20),
                        $this->faker->randomFloat(6, 40, 50),
                        $this->faker->randomFloat(2, 0, 1000),
                    ],
                ],
            ],
        ]);

        return [
            'name' => [
                'it' => $this->faker->sentence(3),
                'en' => $this->faker->sentence(3),
            ],
            'app_id' => App::first()->id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
            'osmid' => $this->faker->optional(0.7)->numberBetween(1000000, 9999999),
            'properties' => [
                'description' => $this->faker->paragraph(),
                'excerpt' => $this->faker->sentence(),
                'difficulty' => $this->faker->randomElement(['facile', 'media', 'difficile']),
                'rating' => $this->faker->numberBetween(1, 5),
                'distance' => $this->faker->randomFloat(2, 1, 30),
                'ascent' => $this->faker->numberBetween(100, 2000),
                'descent' => $this->faker->numberBetween(100, 2000),
                'duration_forward' => $this->faker->randomFloat(2, 1, 10),
                'duration_backward' => $this->faker->randomFloat(2, 1, 10),
                'cai_scale' => $this->faker->randomElement(['T', 'E', 'EE', 'EEA']),
                'from' => $this->faker->city(),
                'to' => $this->faker->city(),
                'ref' => $this->faker->bothify('??-###'),
                'color' => '#' . $this->faker->hexColor(),
                'created_at' => $this->faker->dateTimeThisYear->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
