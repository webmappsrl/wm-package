<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\Media;

class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition()
    {
        // Dummy GeoJSON per un Point (il campo geometry è richiesto nella migration)
        $geojson = json_encode([
            'type' => 'Point',
            'coordinates' => [
                $this->faker->randomFloat(6, 10, 20),
                $this->faker->randomFloat(6, 40, 50),
            ],
        ]);

        return [
            'model_type' => 'Wm\\WmPackage\\Models\\UgcTrack',
            'model_id' => 1,
            'conversions_disk' => $this->faker->randomElement(['public', 's3']),
            'collection_name' => 'default',
            'name' => $this->faker->word,
            'file_name' => $this->faker->word . '.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'size' => $this->faker->numberBetween(1000, 5000),
            'manipulations' => [
                'thumb' => [
                    'width' => 100,
                    'height' => 100,
                    'fit' => 'crop',
                ],
                'medium' => [
                    'width' => 400,
                    'height' => 300,
                    'fit' => 'contain',
                ],
                'watermark' => [
                    'opacity' => 50,
                    'position' => 'bottom-right',
                    'width' => 200,
                ],
            ],
            'custom_properties' => [
                'caption' => $this->faker->sentence,
                'alt_text' => $this->faker->words(3, true),
                'location' => [
                    'city' => $this->faker->city,
                    'country' => $this->faker->country,
                ],
                'taken_at' => $this->faker->dateTimeThisYear->format('Y-m-d H:i:s'),
            ],
            'generated_conversions' => [
                'thumb' => true,
                'medium' => true,
                'watermark' => false,
            ],
            'responsive_images' => [
                'media_library_original' => [
                    'urls' => [
                        '300w' => 'image-300.jpg',
                        '600w' => 'image-600.jpg',
                        '900w' => 'image-900.jpg',
                    ],
                    'base64svg' => 'data:image/svg+xml;base64,PHN2ZyB3aWR0...',
                ],
            ],
            'order_column' => $this->faker->numberBetween(1, 100),
            'geometry' => \DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
            'app_id' => 1,
        ];
    }
}
