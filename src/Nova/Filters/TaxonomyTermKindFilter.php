<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

/**
 * Separa i tassonomie dai termini (oc:8910): nella stessa tabella convivono le due cose, e senza
 * questo filtro l'elenco le mescola.
 */
class TaxonomyTermKindFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Kind');
    }

    public function apply(Request $request, $query, $value)
    {
        return $value === 'vocabularies'
            ? $query->whereNull('parent_id')
            : $query->whereNotNull('parent_id');
    }

    public function options(Request $request): array
    {
        return [
            __('Taxonomies') => 'vocabularies',
            __('Terms') => 'terms',
        ];
    }
}
