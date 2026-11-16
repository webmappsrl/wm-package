<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Support\TaxonomyTermLabel;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * Aggiorna la percorribilita' di un sentiero dal backoffice (oc:8989).
 *
 * E' l'unico punto da cui una persona scrive questo dato: nel form di modifica
 * il campo non c'e', perche' un campo come gli altri si salverebbe senza data,
 * senza autore e senza nota — il difetto che su osm2cai lascia all'Administrator
 * un `Select` che cambia lo stato senza lasciare traccia.
 *
 * Data e autore non si chiedono: li mette il service, con l'utente loggato.
 */
class UpdateWalkability extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Update walkability');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(WalkabilityService::class);

        /** @var string $toIdentifier */
        $toIdentifier = $fields->walkability_status;
        /** @var string|null $description */
        $description = $fields->description;

        foreach ($models as $track) {
            if (! $track instanceof EcTrack) {
                continue;
            }

            // `auth()->user()` e non `request()->user()`: in un'Action invocata
            // direttamente il kernel HTTP non passa, e la seconda e' nulla.
            $service->set($track, $toIdentifier, $description, auth()->id(), TrailStatusEventSource::Manual);
        }

        return Action::message(__('Walkability updated.'));
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Select::make(__('Walkability'), 'walkability_status')
                ->options($this->options())
                ->displayUsingLabels()
                ->rules('required'),

            Textarea::make(__('Note'), 'description')
                ->nullable()
                ->help(__('What you saw on the ground: it stays in the history, with your name and the date.')),
        ];
    }

    /**
     * Gli stati sono quelli che lo shard ha configurato, letti dal database:
     * nessun elenco di valori nel codice del package.
     *
     * @return array<string, string>
     */
    private function options(): array
    {
        /** @var string $identifier */
        $identifier = config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status');

        $family = TaxonomyTerm::query()->where('identifier', $identifier)->whereNull('parent_id')->first();

        if ($family === null) {
            return [];
        }

        return $family->children()
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (TaxonomyTerm $term) => [$term->identifier => TaxonomyTermLabel::for($term)])
            ->all();
    }

    /**
     * L'autorizzazione sta qui e non solo sulla Resource: un `canRun()` salta
     * l'autorizzazione della Resource (`ActionModelCollection::filterForExecution()`),
     * quindi senza questo l'azione sarebbe eseguibile da chiunque arrivi
     * all'endpoint.
     */
    public static function allowedFor(NovaRequest $request): bool
    {
        return TrailRegistryPolicy::allows($request->user());
    }
}
