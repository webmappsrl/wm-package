<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class EcPoiRegionFilter extends Filter
{
    public $component = 'select-filter';

    // Mappa osmfeatures_id => nome regione (fonte: osmfeatures.maphub.it, admin_level=4, Italia)
    private const REGIONS = [
        'R53937'   => 'Abruzzo',
        'R40137'   => 'Basilicata',
        'R1783980' => 'Calabria',
        'R40218'   => 'Campania',
        'R42611'   => 'Emilia-Romagna',
        'R179296'  => 'Friuli-Venezia Giulia',
        'R40784'   => 'Lazio',
        'R301482'  => 'Liguria',
        'R44879'   => 'Lombardia',
        'R53060'   => 'Marche',
        'R41256'   => 'Molise',
        'R44874'   => 'Piemonte',
        'R40095'   => 'Puglia',
        'R7361997' => 'Sardegna',
        'R39152'   => 'Sicilia',
        'R41977'   => 'Toscana',
        'R45757'   => 'Trentino-Alto Adige',
        'R42004'   => 'Umbria',
        'R45155'   => "Valle d'Aosta",
        'R43648'   => 'Veneto',
    ];

    public function name()
    {
        return __('Region');
    }

    public function apply(Request $request, $query, $value)
    {
        return $query->whereRaw("jsonb_exists((properties->'taxonomy_where')::jsonb, ?)", [$value]);
    }

    public function options(Request $request): array
    {
        return array_flip(self::REGIONS);
    }
}
