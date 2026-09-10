<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly as AnomalyModel;

/**
 * La legenda della mappa nella scheda di un'anomalia.
 *
 * Le voci sono le stesse che la mappa disegna davvero, e cambiano con il tipo:
 * una legenda che nomina un elemento assente fa cercare all'operatore
 * qualcosa che non c'è. Colori e spessori vanno tenuti allineati a
 * {@see AnomalyModel::getFeatureCollectionMap()} — sono due letture della
 * stessa scelta, e divergere significa mentire su cosa si sta guardando.
 *
 * Legenda in HTML statico accanto alla mappa e non dentro di essa, come per
 * il registro: il componente della mappa è condiviso e compilato, aggiungerci
 * un riquadro vorrebbe dire un bundle da mantenere allineato in cambio di
 * nulla.
 */
class AnomalyMapLegendRenderer
{
    public static function render(AnomalyModel $anomaly): string
    {
        if ($anomaly->type === null || $anomaly->ec_track_id === null) {
            return '';
        }

        $context = $anomaly->context ?? [];

        // Le voci portano i NOMI dei sentieri, non il loro ruolo: «quello
        // rimasto senza numero» o «quelli con la stessa traccia» valgono per
        // ogni riga di questa lista e non dicono quale sentiero sia quale.
        // Il nome sì, ed è anche l'unica cosa che si ritrova poi sulla mappa
        // passandoci sopra.
        $rows = [
            self::line(
                'rgba(220, 38, 38, 1)',
                $anomaly->type === TrailRegistryAnomalyType::GeometriaDuplicata ? 12 : 7,
                self::nameOf($context['track'] ?? null, $anomaly->ec_track_id),
            ),
        ];

        foreach (self::counterparts($anomaly, $context) as [$color, $width, $track]) {
            $rows[] = self::line($color, $width, self::nameOf($track, null));
        }

        $sectorCount = count(array_filter(
            $anomaly->getFeatureCollectionMap()['features'],
            fn (array $f) => isset($f['properties']['taxonomy_where_id']),
        ));

        $rows[] = self::area(
            'rgba(37, 99, 235, 1)',
            'rgba(37, 99, 235, 0.20)',
            __('Settore in cui la traccia ricade'),
        );

        if ($sectorCount > 1) {
            $rows[] = self::area(
                'rgba(100, 116, 139, 1)',
                'rgba(100, 116, 139, 0.15)',
                $anomaly->type === TrailRegistryAnomalyType::SettoreDiscordante
                    ? __('Altri settori: quelli attraversati e quello dichiarato dal codice')
                    : __('Altri settori attraversati, con la percentuale di percorso'),
            );
        }

        return '<ul style="margin:0;padding:0;font-size:0.875rem">'
            .implode('', array_filter($rows))
            .'</ul>';
    }

    /**
     * Gli altri sentieri disegnati, con il colore e lo spessore che hanno
     * sulla mappa. Vanno tenuti allineati a
     * {@see AnomalyModel::getFeatureCollectionMap()}: sono due letture della
     * stessa scelta, e divergere significa mentire su cosa si sta guardando.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{0: string, 1: int, 2: array<string, mixed>|null}>
     */
    protected static function counterparts(AnomalyModel $anomaly, array $context): array
    {
        if ($anomaly->type === TrailRegistryAnomalyType::CodiceGiaAssegnato) {
            $assignee = $context['assigned_to'] ?? null;

            return is_array($assignee) ? [['rgba(22, 163, 74, 1)', 5, $assignee]] : [];
        }

        if ($anomaly->type === TrailRegistryAnomalyType::GeometriaDuplicata) {
            $twins = is_array($context['twins'] ?? null) ? $context['twins'] : [];

            return array_map(
                // Bianca e piu' sottile: corre dentro la traccia rossa, che
                // resta visibile ai due lati.
                fn ($twin) => ['rgba(255, 255, 255, 1)', 4, is_array($twin) ? $twin : null],
                $twins,
            );
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $track
     */
    protected static function nameOf(?array $track, ?int $fallbackId): string
    {
        $name = is_string($track['name'] ?? null) && trim($track['name']) !== ''
            ? $track['name']
            : null;

        return $name ?? ('#'.($track['id'] ?? $fallbackId ?? '?'));
    }

    protected static function line(string $stroke, int $width, string $label): string
    {
        // Il campione si rimpicciolisce: sulla mappa 12 pixel servono a far
        // spazio alla linea che ci corre dentro, in una legenda sarebbero un
        // blocco. Il bianco prende un contorno, altrimenti su fondo chiaro
        // non si vedrebbe affatto.
        $thickness = min($width, 6);
        $outline = $stroke === 'rgba(255, 255, 255, 1)'
            ? ';outline:1px solid rgba(148,163,184,1)'
            : '';

        $swatch = sprintf(
            '<span style="display:inline-block;width:22px;height:0;border-top:%dpx solid %s;vertical-align:middle%s"></span>',
            $thickness,
            e($stroke),
            $outline,
        );

        return self::row($swatch, $label);
    }

    protected static function area(string $stroke, string $fill, string $label): string
    {
        $swatch = sprintf(
            '<span style="display:inline-block;width:22px;height:12px;background:%s;border:2px solid %s;vertical-align:middle"></span>',
            e($fill),
            e($stroke),
        );

        return self::row($swatch, $label);
    }

    protected static function row(string $swatch, string $label): string
    {
        return sprintf(
            '<li style="margin:0 0 6px 0;list-style:none">%s <span style="margin-left:8px">%s</span></li>',
            $swatch,
            e($label),
        );
    }
}
