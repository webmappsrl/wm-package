<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Support\TaxonomyTermLabel;

/**
 * Mostra i soli termini di una tassonomia (oc:8910).
 *
 * L'elenco dei tassonomie si legge dal database: una tassonomia creata da Nova compare nel filtro
 * senza toccare codice. I tassonomie stesse restano fuori dal risultato, perché il filtro guarda
 * `parent_id`.
 */
class TermsByTaxonomyFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        // Filter::name() non passa da Nova::__(): la chiave va tradotta qui.
        return __('Taxonomy');
    }

    public function apply(Request $request, $query, $value)
    {
        return $query->where('parent_id', $value);
    }

    public function options(Request $request): array
    {
        return TaxonomyTerm::query()
            ->taxonomies()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (TaxonomyTerm $taxonomy) => [
                // Chiave = etichetta mostrata, valore = id filtrato.
                TaxonomyTermLabel::for($taxonomy) => $taxonomy->id,
            ])
            ->all();
    }
}
