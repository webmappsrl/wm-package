<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\UgcTrack;

class UgcTrackFactory extends Factory
{
    protected $model = UgcTrack::class;

    public function definition()
    {
        // Dummy GeoJSON per una LineString
        $geojson = json_encode([
            'type' => 'LineString',
            'coordinates' => [
                [
                    $this->faker->randomFloat(6, 10, 20),
                    $this->faker->randomFloat(6, 40, 50),
                ],
                [
                    $this->faker->randomFloat(6, 10, 20),
                    $this->faker->randomFloat(6, 40, 50),
                ],
            ],
        ]);

        return [
            'user_id' => $this->faker->numberBetween(1, 100),
            'app_id' => $this->faker->numberBetween(1, 100),
            'properties' => [
                'description' => $this->faker->paragraph,
                'difficulty' => $this->faker->randomElement(['easy', 'medium', 'hard']),
                'rating' => $this->faker->numberBetween(1, 5),
                'tags' => $this->faker->words(3),
                'contact' => [
                    'phone' => $this->faker->phoneNumber,
                    'email' => $this->faker->email,
                    'website' => $this->faker->url,
                ],
                'opening_hours' => [
                    'monday' => '9:00-18:00',
                    'tuesday' => '9:00-18:00',
                    'wednesday' => '9:00-18:00',
                    'thursday' => '9:00-18:00',
                    'friday' => '9:00-18:00',
                ],
                'created_at' => $this->faker->dateTimeThisYear->format('Y-m-d H:i:s'),
            ],
            'name' => $this->faker->name,
            'geometry' => \DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
        ];
    }
}
