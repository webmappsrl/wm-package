<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Support\TaxonomyTermLabel;
use Wm\WmPackage\Support\TaxonomyTermOptions;

/**
 * Filtro Nova sui termini di una tassonomia di `taxonomy_terms` (oc:8988).
 *
 * Si scrive una volta e si richiama una volta per tassonomia, con il suo identifier:
 * `new TaxonomyTermFilter('route-type', multiple: true)`. Le opzioni sono i termini figli, con
 * la stessa etichetta e la stessa icona del form (`TaxonomyTermOptions`); il valore è
 * l'identifier del termine, stabile fra ambienti, perché finisce nell'URL dell'index.
 *
 * In multiple i valori dello stesso filtro valgono in OR; più filtri sulla stessa Resource si
 * combinano in AND, e per questo ognuno ha una `key()` propria. La modalità è indipendente da
 * `single_choice` della tassonomia: quella dice quanti termini può avere un contenuto, non
 * quanti ne può cercare chi filtra.
 */
class TaxonomyTermFilter extends ChoiceFilter
{
    private ?TaxonomyTerm $taxonomy = null;

    private bool $taxonomyLoaded = false;

    public function __construct(private readonly string $taxonomyIdentifier, bool $multiple = false)
    {
        $this->multiple($multiple);
    }

    /**
     * Una chiave per tassonomia: con la chiave di default (il nome della classe) Nova
     * confonderebbe le istanze e ne applicherebbe una sola.
     */
    public function key(): string
    {
        return 'taxonomy_term_'.$this->taxonomyIdentifier;
    }

    /**
     * Il nome della tassonomia nella lingua corrente; l'identifier se la tassonomia non esiste.
     */
    public function name(): string
    {
        $taxonomy = $this->taxonomy();

        return $taxonomy === null ? $this->taxonomyIdentifier : TaxonomyTermLabel::for($taxonomy);
    }

    protected function choiceOptions(NovaRequest $request): array
    {
        $taxonomy = $this->taxonomy();

        return $taxonomy === null ? [] : TaxonomyTermOptions::for($taxonomy, 'identifier');
    }

    /**
     * Solo identifier figli di questa tassonomia. Se non ne resta nessuno — un link salvato con un
     * termine rinominato o ritirato — il risultato è vuoto: un filtro non allarga mai i risultati.
     */
    protected function applyChoice(NovaRequest $request, Builder $query, string|array $value): Builder
    {
        $taxonomy = $this->taxonomy();
        $valid = $taxonomy === null
            ? []
            : $taxonomy->children->pluck('identifier')->intersect((array) $value)->values()->all();

        if ($valid === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('taxonomyTerms', fn ($terms) => $terms->whereIn('taxonomy_terms.identifier', $valid));
    }

    /**
     * La tassonomia, letta una volta per istanza: nome, opzioni e query la chiedono tutti.
     */
    private function taxonomy(): ?TaxonomyTerm
    {
        if (! $this->taxonomyLoaded) {
            $this->taxonomy = TaxonomyTerm::query()
                ->taxonomies()
                ->where('identifier', $this->taxonomyIdentifier)
                ->first();
            $this->taxonomyLoaded = true;
        }

        return $this->taxonomy;
    }
}
