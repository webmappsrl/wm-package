<?php

namespace Wm\WmPackage\Nova\Menu;

use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Nova;
use Wm\WmPackage\Nova\AbstractTaxonomyTermsResource;

/**
 * Le voci di menu delle Resource dedicate a una tassonomia di termini (oc:8910).
 *
 * Chi monta il package scrive `...TaxonomyTermsMenu::items($request)` dentro la sezione delle
 * tassonomie: ogni Resource che estende `AbstractTaxonomyTermsResource` compare da sé, in ordine
 * alfabetico per nome della tassonomia. Aggiungere una tassonomia non richiede più di toccare il
 * menu, che è il passaggio che si dimentica.
 */
class TaxonomyTermsMenu
{
    /**
     * @return list<MenuItem>
     */
    public static function items(Request $request): array
    {
        $classes = [];
        foreach (Nova::authorizedResources($request) as $class) {
            if (is_subclass_of($class, AbstractTaxonomyTermsResource::class)) {
                $classes[$class] = $class;
            }
        }

        // L'etichetta viene dal nome della tassonomia sul database: una query per voce, su una
        // tabella di poche righe. È la stessa che Nova farebbe comunque per intitolare la voce.
        $classes = array_values($classes);
        usort($classes, fn (string $a, string $b) => strcasecmp($a::label(), $b::label()));

        return array_map(fn (string $class) => MenuItem::resource($class), $classes);
    }
}
