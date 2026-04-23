<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class TaxonomyWhereAdminLevelFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Admin Level');
    }

    public function apply(Request $request, $query, $value)
    {
        return $query->whereRaw("(properties->>'admin_level')::int = ?", [(int) $value]);
    }

    public function options(Request $request): array
    {
        return [
            'Regione (L4)'    => 4,
            'Provincia (L6)'  => 6,
            'Comune (L8)'     => 8,
            'Municipio (L9)'  => 9,
            'Quartiere (L10)' => 10,
        ];
    }
}
