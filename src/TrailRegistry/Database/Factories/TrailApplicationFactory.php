<?php

namespace Wm\WmPackage\TrailRegistry\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

class TrailApplicationFactory extends Factory
{
    protected $model = TrailApplication::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => 'api',
            'status' => TrailApplicationStatus::UnderReview,
            'name' => $this->faker->words(3, true),
            // properties come array, non stringa JSON: il cast e' 'array' e
            // una stringa produrrebbe doppia serializzazione (vedi la nota su
            // EcPoiFactory nel CLAUDE.md del package).
            'properties' => [],
        ];
    }
}
