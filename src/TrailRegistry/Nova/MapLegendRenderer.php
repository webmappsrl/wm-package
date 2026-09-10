<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;

/**
 * La legenda della mappa della scheda di un codice.
 *
 * E' HTML statico e non un componente della mappa: osm2cai la disegna dentro
 * il proprio campo `SignageMap`, che per questo ha un bundle JavaScript suo.
 * Qui le voci sono tre, fisse e di colore fisso, quindi un bundle da
 * mantenere allineato darebbe la stessa informazione a un costo molto piu'
 * alto — e il package ha gia' avuto problemi con le compilazioni dei campi
 * Nova (conflitti PostCSS, versioni di webpack da bloccare).
 *
 * Se un giorno la legenda dovra' stare dentro la mappa, accendersi e spegnersi
 * con i livelli o seguire la vista, allora servira' il componente proprio: e'
 * quello il momento di scriverlo, non prima.
 *
 * I colori sono gli stessi di
 * {@see TrailRegistryCodeModel::getFeatureCollectionMap()} e vanno cambiati
 * insieme: sono l'unica cosa che lega i due file.
 */
class MapLegendRenderer
{
    /**
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    protected const ENTRIES = [
        'sector' => ['rgba(37, 99, 235, 1)', 'rgba(37, 99, 235, 0.20)', 'Settore da cui viene il prefisso'],
        'other_sectors' => ['rgba(100, 116, 139, 1)', 'rgba(100, 116, 139, 0.15)', 'Altri settori attraversati, con la percentuale di percorso'],
        'track' => ['rgba(22, 163, 74, 1)', '', 'Sentiero a cui il codice e\' assegnato'],
        'application' => ['rgba(234, 88, 12, 1)', '', 'Traccia dell\'istanza da cui il codice e\' nato'],
    ];

    public static function render(TrailRegistryCodeModel $code): string
    {
        $rows = [];

        // Quanti settori la mappa disegna davvero: serve a sapere se la voce
        // «altri settori» ha senso. La collection si compone una volta sola.
        $sectorCount = count(array_filter(
            $code->getFeatureCollectionMap()['features'],
            fn (array $f) => isset($f['properties']['taxonomy_where_id']),
        ));

        foreach (self::ENTRIES as $key => [$stroke, $fill, $label]) {
            if (! self::isPresent($code, $key, $sectorCount)) {
                continue;
            }

            $swatch = $fill === ''
                ? sprintf(
                    '<span style="display:inline-block;width:22px;height:0;border-top:3px solid %s;vertical-align:middle"></span>',
                    e($stroke),
                )
                : sprintf(
                    '<span style="display:inline-block;width:22px;height:12px;background:%s;border:2px solid %s;vertical-align:middle"></span>',
                    e($fill),
                    e($stroke),
                );

            $rows[] = sprintf(
                '<li style="margin:0 0 6px 0;list-style:none">%s <span style="margin-left:8px">%s</span></li>',
                $swatch,
                e(__($label)),
            );
        }

        if ($rows === []) {
            return '<p>'.e(__('Nessuna geometria da mostrare per questo codice.')).'</p>';
        }

        return '<ul style="margin:0;padding:0;font-size:0.875rem">'.implode('', $rows).'</ul>';
    }

    /**
     * Le voci si mostrano solo se sulla mappa ci sono davvero: una legenda che
     * nomina un elemento assente fa cercare all'operatore qualcosa che non c'e'.
     *
     * Per gli altri settori la risposta non e' deducibile dalle colonne — un
     * tracciato puo' attraversarne uno solo — quindi si conta quanti settori la
     * mappa ha davvero composto.
     */
    protected static function isPresent(TrailRegistryCodeModel $code, string $key, int $sectorCount): bool
    {
        return match ($key) {
            'sector' => $code->taxonomy_where_id !== null,
            'other_sectors' => $sectorCount > 1,
            'track' => $code->ec_track_id !== null,
            'application' => $code->trail_application_id !== null,
            default => false,
        };
    }
}
