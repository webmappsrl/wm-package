<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;

/**
 * La legenda della mappa della scheda di un codice.
 *
 * E' HTML statico e non un componente della mappa: osm2cai la disegna dentro
 * il proprio campo `SignageMap`, che per questo ha un bundle JavaScript suo.
 * Qui le voci sono poche, fisse e di colore fisso, quindi un bundle da
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
        'sector' => ['rgba(37, 99, 235, 1)', 'rgba(37, 99, 235, 0.20)', 'Sector the prefix comes from'],
        'other_sectors' => ['rgba(100, 116, 139, 1)', 'rgba(100, 116, 139, 0.15)', 'Other sectors crossed, with the percentage of the route'],
        'neighbours' => ['rgba(100, 116, 139, 0.9)', '', 'Other trails in the sector, with number and variant'],
        'track' => ['rgba(22, 163, 74, 1)', '', 'Trail the code is assigned to'],
        'application' => ['rgba(234, 88, 12, 1)', '', 'Application track the code originated from'],
    ];

    /**
     * `$subject` e' il punto di vista della scheda che mostra la legenda: deve
     * essere lo stesso della mappa accanto, altrimenti la voce del profilo
     * nominerebbe la linea sbagliata (oc:8662).
     */
    public static function render(TrailRegistryCodeModel $code, string $subject = TrailRegistryCodeModel::MAP_SUBJECT_CODE): string
    {
        $rows = [];

        // La collection si compone una volta sola: da qui si contano sia i
        // settori — per sapere se la voce «altri settori» ha senso — sia i
        // vicini, che nelle colonne non si vedono affatto.
        $features = $code->getFeatureCollectionMap($subject)['features'];

        $sectorCount = count(array_filter(
            $features,
            fn (array $f) => isset($f['properties']['taxonomy_where_id']),
        ));

        $neighbourCount = count(array_filter(
            $features,
            fn (array $f) => ($f['properties']['neighbour'] ?? false) === true,
        ));

        foreach (self::ENTRIES as $key => [$stroke, $fill, $label]) {
            if (! self::isPresent($code, $key, $sectorCount, $neighbourCount)) {
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

        foreach (self::signRows($features) as $row) {
            $rows[] = $row;
        }

        $subjectFeature = collect($features)->first(fn (array $f) => ($f['properties']['slopeChart'] ?? false) === true);

        if ($subjectFeature !== null) {
            $rows[] = sprintf(
                '<li style="margin:0 0 6px 0;list-style:none">%s</li>',
                e(($subjectFeature['properties']['subjectKind'] ?? '') === 'track'
                    ? __('Elevation profile below the map: of the trail')
                    : __('Elevation profile below the map: of the application track')),
            );
        }

        if ($rows === []) {
            return '<p>'.e(__('No geometry to show for this code.')).'</p>';
        }

        return '<ul style="margin:0;padding:0;font-size:0.875rem">'.implode('', $rows).'</ul>';
    }

    /**
     * I segnavia, con gli stessi colori del componente Vue
     * (`TrailRegistryMapField/resources/js/trail-sign.mjs`): vanno cambiati
     * insieme (oc:8662). [bande, bordo, barrato, etichetta]
     *
     * @var array<string, array{0: string|null, 1: string, 2: bool, 3: string}>
     */
    protected const SIGNS = [
        'current' => ['rgba(234, 88, 12, 1)', 'rgba(234, 88, 12, 1)', false, 'Number of this code'],
        'released' => ['rgba(148, 163, 184, 1)', 'rgba(148, 163, 184, 1)', true, 'Released number: no longer belongs to this application'],
        'assigned' => ['rgba(220, 38, 38, 1)', 'rgba(220, 38, 38, 1)', false, 'Number of a validated trail'],
        'reserved' => [null, 'rgba(220, 38, 38, 1)', false, 'Number proposed by another application'],
    ];

    /**
     * Una voce per ogni tipo di segnavia davvero presente sulla mappa, per la
     * stessa ragione delle altre voci: una legenda che nomina un elemento
     * assente lo fa cercare.
     *
     * @param  array<int, array<string, mixed>>  $features
     * @return array<int, string>
     */
    protected static function signRows(array $features): array
    {
        $present = [];

        foreach ($features as $f) {
            $p = $f['properties'];

            if (($p['current'] ?? false) === true) {
                $present[($p['codeStatus'] ?? '') === 'released' ? 'released' : 'current'] = true;
            } elseif (($p['neighbour'] ?? false) === true && isset($p['codeStatus'])) {
                $present[$p['codeStatus']] = true;
            }
        }

        $rows = [];

        foreach (self::SIGNS as $key => [$band, $border, $strike, $label]) {
            if (! isset($present[$key])) {
                continue;
            }

            $bandCss = $band ?? '#fff';
            $middle = $strike
                ? sprintf('background:linear-gradient(to bottom right,transparent 44%%,%1$s 44%%,%1$s 56%%,transparent 56%%),#fff', e($border))
                : 'background:#fff';

            $swatch = sprintf(
                '<span style="display:inline-flex;flex-direction:column;width:24px;border:1px solid %s;vertical-align:middle">'
                .'<span style="height:4px;background:%s"></span><span style="height:8px;%s"></span><span style="height:4px;background:%s"></span></span>',
                e($border),
                e($bandCss),
                $middle,
                e($bandCss),
            );

            $rows[] = sprintf(
                '<li style="margin:0 0 6px 0;list-style:none">%s <span style="margin-left:8px">%s</span></li>',
                $swatch,
                e(__($label)),
            );
        }

        return $rows;
    }

    /**
     * Le voci si mostrano solo se sulla mappa ci sono davvero: una legenda che
     * nomina un elemento assente fa cercare all'operatore qualcosa che non c'e'.
     *
     * Per gli altri settori e per i vicini la risposta non e' deducibile dalle
     * colonne — un tracciato puo' attraversare un solo settore, e un settore
     * puo' non avere altri sentieri — quindi si contano le feature che la mappa
     * ha davvero composto.
     */
    protected static function isPresent(
        TrailRegistryCodeModel $code,
        string $key,
        int $sectorCount,
        int $neighbourCount,
    ): bool {
        return match ($key) {
            'sector' => $code->taxonomy_where_id !== null,
            'other_sectors' => $sectorCount > 1,
            'neighbours' => $neighbourCount > 0,
            'track' => $code->ec_track_id !== null,
            'application' => $code->trail_application_id !== null,
            default => false,
        };
    }
}
