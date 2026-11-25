<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Outl1ne\MultiselectField\Multiselect;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailApplicationTransitionException;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailChoicesException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Rules\DifficultyMatchesActivities;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Approva l'istanza dal dettaglio di Nova. Cosa vuol dire approvare — creare il
 * sentiero, attaccargli il tipo, replicare i media, rendere definitivo il codice
 * — e' in TrailRegistryService::approve(): qui c'e' solo l'interfaccia.
 */
class ApproveTrailApplication extends Action
{
    /**
     * Prefisso dei campi di difficolta': il resto del nome e' l'identifier
     * della famiglia (`difficulty__hiking-difficulty`).
     */
    public const DIFFICULTY_FIELD_PREFIX = 'difficulty__';

    use InteractsWithQueue, Queueable;

    /**
     * Solo dal dettaglio: approvare vuol dire assegnare un codice guardando la
     * mappa della singola istanza (oc:8567).
     */
    public $onlyOnDetail = true;

    public function name(): string
    {
        return __('Approve');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(TrailRegistryService::class);

        $approved = 0;
        $refused = 0;

        foreach ($models as $application) {
            // La guardia sullo stato e' nel service, non solo in canRun():
            // l'autorizzazione dell'interfaccia non e' un presidio, l'endpoint
            // Nova puo' essere invocato direttamente.
            try {
                $service->approve($application, auth()->id(), $this->choices($fields));
                $approved++;
            } catch (InvalidTrailApplicationTransitionException) {
                $refused++;
            } catch (InvalidTrailChoicesException $e) {
                // La transazione e' tornata indietro e l'istanza e' ancora in
                // istruttoria: senza questo l'operatore vedrebbe un 500 di Nova.
                // Si ferma al primo rifiuto: le scelte valgono per tutte le istanze.
                return Action::danger(__('Approval stopped: :reason', ['reason' => $e->getMessage()]));
            }
        }

        if ($approved === 0) {
            return Action::danger(__('No application approved: only applications under review can be approved.'));
        }

        if ($refused > 0) {
            return Action::message(__('Applications approved: :approved. Skipped because not under review: :refused.', [
                'approved' => $approved,
                'refused' => $refused,
            ]));
        }

        return Action::message(__('Applications approved.'));
    }

    /**
     * Le tre scelte dell'operatore, nella forma che approve() si aspetta: i
     * campi di difficolta' (uno per famiglia, `difficulty__<famiglia>`) si
     * raccolgono in una mappa famiglia => livello. Il service decide cosa
     * scrivere: qui non si filtra niente.
     *
     * @return array{activities: list<string>, difficulty: array<string, string>, primary_activity: string|null}
     */
    protected function choices(ActionFields $fields): array
    {
        $difficulty = [];

        foreach ($fields->toArray() as $name => $value) {
            if (str_starts_with((string) $name, self::DIFFICULTY_FIELD_PREFIX) && is_string($value) && $value !== '') {
                $difficulty[substr((string) $name, strlen(self::DIFFICULTY_FIELD_PREFIX))] = $value;
            }
        }

        $activities = $fields->get('activities');

        return [
            'activities' => is_array($activities) ? array_values($activities) : [],
            'difficulty' => $difficulty,
            'primary_activity' => $fields->get('primary_activity') ?: null,
        ];
    }

    /**
     * Le attivita' del sentiero, gia' compilate con quanto il SUS ha proposto
     * (oc:9038). Le opzioni sono le attivita' dello schema e non le righe di
     * `taxonomy_activities`, che contiene anche `trail`, `itinerary` e `walk`:
     * tipi di tracciato, non attivita'.
     */
    public function fields(NovaRequest $request): array
    {
        $apiData = $this->applicationOf($request)?->api_data;
        $apiData = is_array($apiData) ? $apiData : [];

        $proposedActivities = $this->allowedOnly($apiData['activities'] ?? [], $this->activityOptions());
        $primaryOptions = $this->activityOptions($proposedActivities);
        $proposedPrimary = $apiData['primary_activity'] ?? null;

        return array_merge([
            Multiselect::make(__('Activities'), 'activities')
                ->options($this->activityOptions())
                // saveAsJSON() non basta: Multiselect decide guardando getCasts()
                // del modello, che un ActionFields (Fluent) non ha, e salva una
                // stringa JSON. Il Task 4 si aspetta l'array di identifier.
                ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                    $value = $request->input($requestAttribute);
                    $value = is_string($value) ? json_decode($value, true) : $value;

                    $model->{$attribute} = is_array($value) ? array_values($value) : [];
                })
                ->default(fn () => $proposedActivities === [] ? null : $proposedActivities),

            Select::make(__('Primary activity'), 'primary_activity')
                ->options($primaryOptions)
                // Obbligatoria qui e non nel service: una decisione senza
                // primaria resta rappresentabile (il canale la restituisce a
                // `null`), ma un operatore non la prende per distrazione.
                // Capita: togliendo dalle attivita' quella prefillata come
                // primaria, la select si svuota da se' e l'approvazione
                // passava con la primaria vuota (oc:9038, prova a mano).
                ->rules('required')
                ->default(fn () => is_string($proposedPrimary) && isset($primaryOptions[$proposedPrimary]) ? $proposedPrimary : null)
                ->dependsOn('activities', function (Select $field, NovaRequest $request, $formData) {
                    // Multiselect manda l'array come stringa JSON.
                    $selected = $formData->get('activities');
                    $selected = is_string($selected) ? json_decode($selected, true) : $selected;

                    $field->options($this->activityOptions(is_array($selected) ? $selected : []));
                }),
        ], $this->difficultyFields($apiData, $proposedActivities));
    }

    /**
     * Un Select per famiglia di difficolta', come sul sentiero: `trekking` e
     * `hiking` condividono la scala, e un payload che propone due livelli
     * diversi per la stessa famiglia lascia la scelta all'operatore.
     *
     * Il nome del campo e' `difficulty__<identifier della famiglia>`
     * (`difficulty__hiking-difficulty`): da quel nome si risale alla famiglia
     * senza una seconda mappa, con `DIFFICULTY_FIELD_PREFIX`. Il valore e' il
     * suffisso del termine (`E` per `hiking-difficulty-E`), lo stesso che l'API
     * riceve. Le famiglie senza attivita' selezionata sono nascoste.
     *
     * @param  array<string, mixed>  $apiData
     * @param  list<string>  $selectedActivities
     * @return list<Select>
     */
    protected function difficultyFields(array $apiData, array $selectedActivities): array
    {
        $schemaActivities = app(ApplicationSchema::class)->activities();
        $families = [];

        foreach (DifficultyMatchesActivities::activityFamilies() as $activity => $family) {
            if (in_array($activity, $schemaActivities, true)) {
                $families[$family][] = $activity;
            }
        }

        $proposed = is_array($apiData['difficulty'] ?? null) ? $apiData['difficulty'] : [];
        $fields = [];

        foreach ($families as $family => $activities) {
            $options = $this->difficultyOptions($family);

            if ($options === []) {
                continue;
            }

            $levels = collect($activities)
                ->map(fn (string $activity) => $proposed[$activity] ?? null)
                ->filter(fn ($level) => is_string($level) && isset($options[$level]))
                ->unique()
                ->values();

            // Due livelli diversi per la stessa scala: nessun default.
            $default = $levels->count() === 1 ? $levels->first() : null;
            $conflict = $levels->count() > 1;

            $field = Select::make(
                __('Difficulty').' — '.$this->termName($family, $family),
                self::DIFFICULTY_FIELD_PREFIX.$family,
            )
                ->options($options)
                ->default(fn () => $default)
                ->dependsOn('activities', function (Select $field, NovaRequest $request, $formData) use ($activities) {
                    $selected = $formData->get('activities');
                    $selected = is_string($selected) ? json_decode($selected, true) : $selected;

                    array_intersect($activities, is_array($selected) ? $selected : []) === []
                        ? $field->hide()
                        : $field->show();
                });

            if ($conflict) {
                $field->help(e(__('The proposal contains different levels for this scale: choose one.')));
            }

            if (array_intersect($activities, $selectedActivities) === []) {
                $field->hide();
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * I livelli di una famiglia: suffisso dell'identifier => nome del termine.
     *
     * @return array<string, string>
     */
    protected function difficultyOptions(string $family): array
    {
        return TaxonomyTerm::query()
            ->whereHas('parent', fn ($parents) => $parents->where('identifier', $family))
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (TaxonomyTerm $term) => [
                substr($term->identifier, strlen($family) + 1) => $this->translatedName($term, $term->identifier),
            ])
            ->all();
    }

    protected function termName(string $identifier, string $fallback): string
    {
        return $this->translatedName(TaxonomyTerm::query()->where('identifier', $identifier)->first(), $fallback);
    }

    /**
     * Le attivita' ammesse dallo schema, etichettate con il nome della
     * `TaxonomyActivity` corrispondente: in forestas i nomi non assomigliano
     * agli identifier (`hiking` e' «Alte vie»). Se la riga manca, l'etichetta
     * e' l'identifier. Con `$only` si restringe alle attivita' indicate, nell'ordine
     * dello schema.
     *
     * @param  list<string>|null  $only
     * @return array<string, string>
     */
    protected function activityOptions(?array $only = null): array
    {
        $identifiers = app(ApplicationSchema::class)->activities();

        if ($only !== null) {
            $identifiers = array_values(array_intersect($identifiers, $only));
        }

        $labels = TaxonomyActivity::query()->whereIn('identifier', $identifiers)->get()->keyBy('identifier');

        return collect($identifiers)
            ->mapWithKeys(fn (string $identifier) => [
                $identifier => $this->translatedName($labels->get($identifier), $identifier),
            ])
            ->all();
    }

    /**
     * Il nome nella lingua corrente, poi in italiano e inglese; senza nome,
     * l'identifier.
     */
    protected function translatedName(TaxonomyActivity|TaxonomyTerm|null $model, string $identifier): string
    {
        $names = $model?->getTranslations('name') ?? [];

        foreach ([app()->getLocale(), 'it', 'en'] as $locale) {
            if (! empty($names[$locale])) {
                return (string) $names[$locale];
            }
        }

        return $identifier;
    }

    /**
     * Tiene dei valori proposti solo quelli fra le opzioni: cio' che lo schema
     * non ammette piu' vale come assente.
     *
     * @return list<string>
     */
    protected function allowedOnly(mixed $values, array $options): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            fn ($value) => is_string($value) && isset($options[$value]),
        ));
    }

    /**
     * L'istanza si raggiunge come in ReplaceTrailCodeNumber: `fields()` non
     * riceve il modello, e Nova usa `resourceId` all'apertura del modale e
     * `resources` (lista di id, o la stringa "all") nella PATCH del dependsOn.
     */
    protected function applicationOf(NovaRequest $request): ?TrailApplication
    {
        $id = $request->resourceId;

        if (! $id) {
            $resources = $request->input('resources');
            $id = is_array($resources) && $resources !== [] ? $resources[0] : null;
        }

        if (! $id || $id === 'all') {
            return null;
        }

        return TrailRegistryClasses::application()::find($id);
    }
}
