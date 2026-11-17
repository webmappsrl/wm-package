<?php

namespace Wm\WmPackage\Support;

use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\IconSvgService;

/**
 * Le opzioni dei termini di una tassonomia nel formato del campo `Choice`, `{value, label, svg?}`
 * (oc:8988).
 *
 * Le usano il form delle tassonomie (`BuildsTaxonomyFields`, con l'id come valore) e il filtro
 * dei termini (`TaxonomyTermFilter`, con l'identifier, che finisce nell'URL dell'index ed è
 * stabile fra ambienti): etichetta e icona di un termine hanno così una fonte sola.
 *
 * Il valore è sempre una stringa: la ricerca del selettore lavora su stringhe.
 */
class TaxonomyTermOptions
{
    /**
     * I termini figli, nell'ordine di `children()` (sort_order, poi id). Un termine senza icona
     * non porta `svg`: il campo lascia lo spazio.
     *
     * @param  'id'|'identifier'  $valueKey
     * @return list<array{value: string, label: string, svg?: string}>
     */
    public static function for(TaxonomyTerm $taxonomy, string $valueKey = 'id'): array
    {
        $icons = new IconSvgService;
        $options = [];

        foreach ($taxonomy->children as $term) {
            $option = [
                'value' => (string) $term->{$valueKey},
                'label' => TaxonomyTermLabel::for($term),
            ];

            // Le icone vengono da un file dello shard: se manca, il termine resta senza.
            $svg = self::iconSvg($icons, $term->icon);
            if ($svg !== null) {
                $option['svg'] = $svg;
            }

            $options[] = $option;
        }

        return $options;
    }

    /**
     * I path dell'icona, senza il tag che li avvolge, perché il componente li inserisce dentro un
     * suo `<svg>`; null se non c'è o se il file delle icone dello shard non è leggibile.
     */
    public static function iconSvg(IconSvgService $icons, ?string $icon): ?string
    {
        if (! is_string($icon) || $icon === '') {
            return null;
        }

        try {
            $svg = $icons->getSvgByName($icon, wrapSvg: false);
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($svg) && $svg !== '' ? $svg : null;
    }
}
