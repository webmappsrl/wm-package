<?php

namespace Wm\WmPackage\TrailRegistry\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

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
                $application->writeApiData(static::completeApiData());

                foreach (TrailApplication::requiredAttachments() as $collection) {
                    $application
                        ->addMedia(UploadedFile::fake()->createWithContent($collection.'.pdf', $collection))
                        ->preservingOriginal()
                        ->toMediaCollection($collection);
                }
            });
    }

    /**
     * Un payload che lo schema accetta, per ogni campo obbligatorio.
     *
     * Non basta riempire ogni campo con una stringa: dopo oc:9039 i campi dei
     * soggetti sono liste di oggetti, e una stringa supererebbe
     * `missingForConfirmation()` lasciando i test a lavorare su un payload che
     * l'API avrebbe rifiutato. Il valore si deduce dalle regole dello schema,
     * non da un elenco di nomi scritto qui: un campo nuovo non obbliga a
     * tornare in questa factory.
     *
     * @return array<string, mixed>
     */
    protected static function completeApiData(): array
    {
        $schema = app(ApplicationSchema::class);
        $rules = $schema->rules();
        $payload = [];

        foreach ($schema->fields() as $field) {
            if (! in_array($field->name, TrailApplication::requiredApiFields(), true)) {
                continue;
            }

            $payload[$field->name] = isset($rules[$field->name.'.*.name'])
                ? [['name' => 'Ente di prova', 'email' => 'ente@example.it', 'subject' => 'organization']]
                : (in_array('array', $field->rules, true) ? ['valore'] : 'valore');
        }

        return $payload;
    }
}
