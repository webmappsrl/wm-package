<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;

class EcPoiFactory extends Factory
{
    protected $model = EcPoi::class;

    public function definition()
    {
        // Dummy GeoJSON for a Point
        $geojson = json_encode([
            'type' => 'Point',
            'coordinates' => [
                $this->faker->randomFloat(6, 10, 20),
                $this->faker->randomFloat(6, 40, 50),
            ],
        ]);

        return [
            'name' => [
                'it' => $this->faker->sentence(3),
                'en' => $this->faker->sentence(3)
            ],
            'app_id' => App::first()->id,
            'geometry' => \DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
            'osmid' => $this->faker->optional(0.7)->numberBetween(1000000, 9999999),
            'properties' => json_encode([
                'capacity' => $this->faker->optional()->numberBetween(5, 100),
                'addr_complete' => $this->faker->address(),
                'contact_phone' => $this->faker->phoneNumber(),
                'contact_email' => $this->faker->email(),
                'related_url' => $this->faker->url(),
                'difficulty' => $this->faker->randomElement(['facile', 'media', 'difficile']),
                'rating' => $this->faker->numberBetween(1, 5),
                'tags' => $this->faker->words(3),
                'contact' => [
                    'phone' => $this->faker->phoneNumber(),
                    'email' => $this->faker->email(),
                    'website' => $this->faker->url(),
                ],
                'opening_hours' => [
                    'lunedì' => '9:00-18:00',
                    'martedì' => '9:00-18:00',
                    'mercoledì' => '9:00-18:00',
                    'giovedì' => '9:00-18:00',
                    'venerdì' => '9:00-18:00',
                ],
                'created_at' => $this->faker->dateTimeThisYear->format('Y-m-d H:i:s'),
            ])
        ];
    }
}
