<?php

declare(strict_types=1);

namespace Wm\WmPackage\Support;

/**
 * Sorgente unica delle chiavi `apps.properties` popolate dall'import Geohub (oc:8488).
 *
 * Due consumer, che DEVONO concordare sul nome della chiave:
 *   - ImportAppJob::transformData()  scrive properties[$key] leggendo la colonna Geohub
 *   - Nova\App                       genera il campo editabile per ogni voce con nova=>true
 *
 * La struttura delle sezioni di config NON sta qui: `TABLES.details.hide_ascent` è la
 * negazione di `table_details_show_ascent`, `OPTIONS.show_scale` non è derivabile dal
 * nome, e 5 campi sono letti in due sezioni con chiavi diverse. Quelle restano esplicite
 * in AppConfigService, che le legge tutte tramite l'unico helper prop()/setProp().
 *
 * Le 4 chiavi theme (primary_color, default_feature_color, font_family_header,
 * font_family_content) NON sono qui: vivono sotto properties.theme e passano da
 * AppConfigService::THEME_KEY_MAP e da Nova\App::theme_tab(), entrambi di oc:8367.
 */
final class ImportedAppProperties
{
    /**
     * @var array<string, array{type: string, geohub?: string, nova?: bool}>
     */
    public const MAP = [
        // --- Gruppo A: destinazione già letta da AppConfigService, campo Nova già presente.
        'show_travel_mode' => ['type' => 'bool', 'nova' => false],
        'show_features_in_viewport' => ['type' => 'bool', 'nova' => false],
        'min_zoom_features_in_viewport' => ['type' => 'int', 'nova' => false],
        'max_zoom_features_in_viewport' => ['type' => 'int', 'nova' => false],
        'show_track_direction_arrow' => ['type' => 'bool', 'nova' => false],
        'show_download_tiles' => ['type' => 'bool', 'nova' => false, 'geohub' => 'show_download_tiles_button'],

        // --- Gruppo B: lette da AppConfigService come attributo inesistente, nessun campo Nova.
        // 'nova' => false (oc:8488, post-review): né wm-core/webmapp-app né Geohub stesso
        // (il suo app/Nova/App.php non le espone) trattano queste chiavi come editabili — il
        // dato resta comunque scritto in properties dall'import e letto da AppConfigService,
        // solo senza un campo Nova per modificarlo a mano.
        'start_url' => ['type' => 'text', 'nova' => false],
        'show_edit_link' => ['type' => 'bool', 'nova' => false],
        'skip_route_index_download' => ['type' => 'bool', 'nova' => false],
        'show_favorites' => ['type' => 'bool', 'nova' => false], // campo Nova già esistente da oc:8176 (tab Frontend) — non generarne un secondo
        'enable_routing' => ['type' => 'bool'],
        'offline_enable' => ['type' => 'bool', 'nova' => false],
        'offline_force_auth' => ['type' => 'bool', 'nova' => false],
        'tracks_on_payment' => ['type' => 'bool', 'nova' => false],
        'table_details_show_gpx_download' => ['type' => 'bool', 'nova' => false],
        'table_details_show_kml_download' => ['type' => 'bool', 'nova' => false],
        'table_details_show_geojson_download' => ['type' => 'bool', 'nova' => false],
        'table_details_show_shapefile_download' => ['type' => 'bool', 'nova' => false],
        'table_details_show_scale' => ['type' => 'bool', 'nova' => false],
        'table_details_show_related_poi' => ['type' => 'bool'],
        'table_details_show_duration_forward' => ['type' => 'bool'],
        'table_details_show_duration_backward' => ['type' => 'bool'],
        'table_details_show_distance' => ['type' => 'bool'],
        'table_details_show_ascent' => ['type' => 'bool'],
        'table_details_show_descent' => ['type' => 'bool'],
        'table_details_show_ele_max' => ['type' => 'bool'],
        'table_details_show_ele_min' => ['type' => 'bool'],
        'table_details_show_ele_from' => ['type' => 'bool'],
        'table_details_show_ele_to' => ['type' => 'bool'],
        'table_details_show_cai_scale' => ['type' => 'bool'],
        'table_details_show_mtb_scale' => ['type' => 'bool'],
        'table_details_show_ref' => ['type' => 'bool'],
        'table_details_show_surface' => ['type' => 'bool'],

        // --- Gruppo C-attivi: su Geohub, mai lette in Maphub. Nuova esposizione nel config.
        'draw_poi_show' => ['type' => 'bool'],
        'show_embedded_html' => ['type' => 'bool'],
        'show_get_directions' => ['type' => 'bool'],
        'show_media_name' => ['type' => 'bool'],
    ];

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Colonna Geohub → chiave properties locale. Coincidono tranne per il rename dichiarato.
     *
     * @return array<string, string>
     */
    public static function geohubColumns(): array
    {
        $out = [];

        foreach (self::MAP as $key => $spec) {
            $out[$spec['geohub'] ?? $key] = $key;
        }

        return $out;
    }

    public static function type(string $key): string
    {
        return self::MAP[$key]['type'] ?? 'text';
    }

    /**
     * Chiavi per cui Nova deve generare un campo. Il gruppo A è escluso: il campo esiste già.
     *
     * @return array<int, string>
     */
    public static function novaKeys(): array
    {
        return array_keys(array_filter(
            self::MAP,
            static fn (array $spec) => ($spec['nova'] ?? true) === true
        ));
    }
}
