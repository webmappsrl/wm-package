<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

class AppFactory extends Factory
{
    protected $model = App::class;

    public function definition()
    {
        return [
            'name' => $this->faker->company(),
            'user_id' => User::first()->id,
            'sku' => $this->faker->unique()->slug(),
            'customer_name' => $this->faker->company(),
            'map_max_zoom' => $this->faker->numberBetween(14, 18),
            'map_min_zoom' => $this->faker->numberBetween(8, 12),
            'map_def_zoom' => $this->faker->numberBetween(12, 14),
            'font_family_header' => 'Roboto Slab',
            'font_family_content' => 'Roboto',
            'default_feature_color' => '#'.$this->faker->hexColor(),
            'primary_color' => '#'.$this->faker->hexColor(),
            'start_url' => '/main/explore',
            'show_edit_link' => $this->faker->boolean(),
            'skip_route_index_download' => $this->faker->boolean(80),
            'poi_min_radius' => $this->faker->randomFloat(1, 0.3, 0.8),
            'poi_max_radius' => $this->faker->randomFloat(1, 0.9, 1.5),
            'poi_icon_zoom' => $this->faker->randomFloat(1, 15, 17),
            'poi_icon_radius' => $this->faker->randomFloat(1, 0.8, 1.2),
            'poi_min_zoom' => $this->faker->randomFloat(1, 12, 14),
            'poi_label_min_zoom' => $this->faker->randomFloat(1, 10, 11),
            'show_track_ref_label' => $this->faker->boolean(),
            'table_details_show_gpx_download' => $this->faker->boolean(),
            'table_details_show_kml_download' => $this->faker->boolean(),
            'table_details_show_related_poi' => $this->faker->boolean(),
            'enable_routing' => $this->faker->boolean(),
            'external_overlays' => null,
            'icon' => null,
            'splash' => null,
            'icon_small' => null,
            'feature_image' => null,
            'default_language' => 'it',
            'available_languages' => json_encode(['it', 'en']),
            'auth_show_at_startup' => $this->faker->boolean(),
            'offline_enable' => $this->faker->boolean(),
            'offline_force_auth' => $this->faker->boolean(),
            'geolocation_record_enable' => $this->faker->boolean(),
            'welcome' => [
                'it' => $this->faker->paragraph(),
                'en' => $this->faker->paragraph(),
            ],
            'translations_it' => json_encode([
                'welcome' => $this->faker->paragraph(),
                'tiles_label' => 'Mappe',
                'overlays_label' => 'Livelli',
            ]),
            'translations_en' => json_encode([
                'welcome' => $this->faker->paragraph(),
                'tiles_label' => 'Maps',
                'overlays_label' => 'Layers',
            ]),
            'classification_start_date' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'classification_end_date' => $this->faker->dateTimeBetween('now', '+1 year'),
            'show_favorites' => $this->faker->boolean(80),
            'track_technical_details' => json_encode([
                'show_ascent' => true,
                'show_ele_to' => true,
                'show_descent' => true,
                'show_ele_max' => true,
                'show_ele_min' => true,
                'show_distance' => true,
                'show_ele_from' => true,
                'show_duration_forward' => true,
                'show_duration_backward' => true,
            ]),
        ];
    }
}
