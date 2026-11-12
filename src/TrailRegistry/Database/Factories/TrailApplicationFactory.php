<?php

namespace Wm\WmPackage\TrailRegistry\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;
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

    /**
     * Un'istanza in compilazione con il fascicolo completo: ogni campo
     * obbligatorio valorizzato e ogni allegato obbligatorio presente.
     *
     * Chi lo usa deve preparare il disco dei media (`Storage::fake`) e l'app
     * di riferimento dei media, come fanno i test sugli allegati.
     */
    public function complete(): static
    {
        return $this
            ->state(['status' => TrailApplicationStatus::UnderCompilation])
            ->afterCreating(function (TrailApplication $application) {
                $application->writeApiData(array_fill_keys(
                    TrailApplication::requiredApiFields(),
                    'valore'
                ));

                foreach (TrailApplication::requiredAttachments() as $collection) {
                    $application
                        ->addMedia(UploadedFile::fake()->createWithContent($collection.'.pdf', $collection))
                        ->preservingOriginal()
                        ->toMediaCollection($collection);
                }
            });
    }
}
