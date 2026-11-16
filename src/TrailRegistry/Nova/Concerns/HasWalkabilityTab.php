<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Concerns;

use Illuminate\Support\Collection;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Support\TaxonomyTermLabel;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Nova\TrailStatusRenderer;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityTaxonomyInstaller;

/**
 * Il blocco della percorribilita' dentro il tab «Stato» (oc:8989).
 *
 * **Perche' un tab e non il campo generico.** La famiglia della percorribilita'
 * e' una tassonomia come le altre, quindi `BuildsTaxonomyFields` le
 * costruirebbe da se' un `Choice` editabile: chi lo salva scrive il pivot e
 * basta — nessun evento, nessuna nota, nessun autore, nessun lock. Sarebbe la
 * strada piu' comoda, e la piu' comoda vince: lo stato cambierebbe senza
 * storia, che e' il difetto di osm2cai che questo lavoro non replica. Qui il
 * campo generico si toglie (`makeTermFieldsForTaxonomies()`) e al suo posto va
 * un `Select` il cui `fillUsing` passa da `WalkabilityService`.
 *
 * **Un tab solo per tutti gli stati.** Percorribilita', validazione e storico
 * stanno nello stesso tab, separati da un titolo ciascuno: chi inserisce un dato
 * vuole vedere lo storico li' sotto, non in un'altra parte della pagina. In
 * detail ogni stato si mostra come un blocco con valore, autore e data, perche'
 * un giudizio sul terreno senza la sua data e' meta' informazione.
 */
trait HasWalkabilityTab
{
    /**
     * I campi del tab, da unire a `taxonomyTabExtraFields()`.
     *
     * @return array<string, array<int, Field>>
     */
    protected function walkabilityTabFields(): array
    {
        if (! config('wm-package.features.trail_registry.enabled')) {
            return [];
        }

        $terms = $this->walkabilityTerms();

        if ($terms->isEmpty()) {
            return [];
        }

        return [
            WalkabilityTaxonomyInstaller::TAB => [
                Heading::make('<h3 class="text-base font-bold">'.e(__('Walkability')).'</h3>')->asHtml(),
                $this->walkabilityDetailBlock(),
                $this->walkabilityStatusField($terms),
                $this->walkabilityNoteField(),
            ],
        ];
    }

    /**
     * Lo stato, nei soli form: in detail lo mostra `walkabilityCurrentField()`,
     * che stampa il nome del termine invece del suo identifier.
     *
     * @param  Collection<int, TaxonomyTerm>  $terms
     */
    protected function walkabilityStatusField(Collection $terms): Select
    {
        $options = $terms->mapWithKeys(fn (TaxonomyTerm $term) => [$term->identifier => TaxonomyTermLabel::for($term)])->all();

        // «Stato», non «Percorribilità»: il titolo sopra il blocco dice già di quale stato si
        // parla, e ripeterlo nell'etichetta del campo è la stessa parola due volte.
        return Select::make(__('Status'), 'walkability_status')
            ->options($options)
            ->displayUsingLabels()
            ->nullable()
            ->onlyOnForms()
            ->resolveUsing(fn ($value, $resource) => $resource instanceof EcTrack && $resource->exists
                ? app(WalkabilityService::class)->current($resource)?->identifier
                : null)
            ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                /** @var string|null $wanted */
                $wanted = $request->input($requestAttribute);

                if ($wanted === null || $wanted === '') {
                    return;
                }

                /** @var string|null $note */
                $note = $request->input('walkability_note');
                $note = is_string($note) && trim($note) !== '' ? $note : null;

                // L'autore si legge dalla richiesta, che in un `fillUsing` c'e' sempre:
                // `auth()` resta come ripiego per le chiamate che non passano dal kernel HTTP.
                $userId = $request->user()?->getKey() ?? auth()->id();

                // Dopo il salvataggio: su una traccia nuova l'id non esiste
                // ancora, e il service lavora su una riga che deve esserci.
                return function () use ($model, $wanted, $note, $userId) {
                    /** @var EcTrack $model */
                    app(WalkabilityService::class)->set(
                        $model,
                        $wanted,
                        $note,
                        $userId === null ? null : (int) $userId,
                        TrailStatusEventSource::Manual,
                    );
                };
            });
    }

    /**
     * La nota del passaggio. Non ha un valore proprio da salvare: la legge il
     * `fillUsing` dello stato, che e' l'unico a scrivere. Senza `fillUsing`
     * vuoto Nova cercherebbe una colonna `walkability_note` sul modello.
     */
    protected function walkabilityNoteField(): Textarea
    {
        return Textarea::make(__('Note'), 'walkability_note')
            ->nullable()
            ->onlyOnForms()
            ->resolveUsing(fn () => null)
            ->fillUsing(fn () => null)
            ->help(__('What you saw on the ground: it stays in the history, with your name and the date.'));
    }

    /**
     * Lo stato di adesso in detail: valore, chi l'ha scritto, quando e con quale nota.
     * Un campo solo, perche' sono una cosa sola — la registrazione piu' recente.
     */
    protected function walkabilityDetailBlock(): Text
    {
        // Nome vuoto: il titolo è l'intestazione che precede il blocco.
        return Text::make(' ', function () {
            if (! $this->resource instanceof EcTrack || ! $this->resource->exists) {
                return null;
            }

            return TrailStatusRenderer::render(
                $this->resource,
                $this->walkabilityTaxonomyIdentifier(),
                __('Not recorded'),
            );
        })->asHtml()->onlyOnDetail();
    }

    /**
     * I termini della famiglia, nell'ordine in cui sono stati installati.
     *
     * @return Collection<int, TaxonomyTerm>
     */
    protected function walkabilityTerms(): Collection
    {
        $family = TaxonomyTerm::query()
            ->where('identifier', $this->walkabilityTaxonomyIdentifier())
            ->whereNull('parent_id')
            ->first();

        return $family === null
            ? new Collection
            : $family->children()->orderBy('sort_order')->orderBy('id')->get();
    }

    protected function walkabilityTaxonomyIdentifier(): string
    {
        /** @var string $identifier */
        $identifier = config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status');

        return $identifier;
    }
}
