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
     * Un payload che lo schema accetta davvero, per ogni campo obbligatorio.
     *
     * Non basta riempire ogni campo con una stringa. Da quando la conferma
     * valida anche i valori, e non solo la loro presenza, un fascicolo
     * «completo» fatto di segnaposto non e' confermabile: un'attivita'
     * inventata, una data mancante o un numero fuori scala lo fanno
     * respingere, e i test lavorerebbero su una domanda che l'API non
     * accetterebbe mai.
     *
     * Il valore si deduce dalle regole dello schema, non da un elenco scritto
     * qui: un campo nuovo non obbliga a tornare in questa factory.
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

            // I soggetti sono liste di oggetti, non di stringhe (oc:9039).
            if (isset($rules[$field->name.'.*.name'])) {
                $payload[$field->name] = [[
                    'name' => 'Ente di prova',
                    'email' => 'ente@example.it',
                    'subject' => 'organization',
                ]];

                continue;
            }

            $payload[$field->name] = match ($field->type) {
                // Un valore dentro l'intervallo di plausibilita' del campo,
                // quando ne ha uno.
                'number' => static::plausibleNumber($field->name),
                'date' => now()->subMonth()->toDateString(),
                // `select` e liste chiuse: il primo valore ammesso dalla
                // regola `in:`, cosi' campi legati fra loro — l'attivita'
                // primaria e le attivita' proposte — cadono sullo stesso.
                'json' => [static::firstAllowedValue($rules[$field->name.'.*'] ?? []) ?? 'valore'],
                default => static::firstAllowedValue($rules[$field->name] ?? [])
                    ?? ($field->values !== null ? ($field->values[0]['value'] ?? 'valore') : 'valore'),
            };
        }

        return $payload;
    }

    /** Il primo valore che una regola `in:a,b,c` ammette, se c'e'. */
    protected static function firstAllowedValue(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                return explode(',', substr($rule, 3))[0];
            }
        }

        return null;
    }

    /** Un numero dentro l'intervallo di plausibilita' del campo. */
    protected static function plausibleNumber(string $field): int
    {
        [$min, $max] = ApplicationSchema::PLAUSIBLE_RANGES[$field] ?? [1, 100];

        return (int) max($min, min($max, 12));
    }
}
