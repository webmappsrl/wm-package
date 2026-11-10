<?php

namespace Wm\WmPackage\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * `properties.taxonomies` dei contenuti: l'unico punto da cui i frontend leggono le tassonomie
 * (oc:8959). La usano il dettaglio della traccia (`EcTrack::getDetailGeojson()`) e le feature di
 * `pois.geojson`, con la stessa forma: identifier della tassonomia → array di termini, ognuno con
 * `identifier`, `name` (tutte le traduzioni), `tab` (il tab in cui Nova mostra quella tassonomia) e
 * i campi utili della sua fonte quando hanno un valore. Le etichette di tassonomie e tab stanno nel
 * config.json dell'app (`AppConfigService::config_section_taxonomy_labels()`).
 *
 * Le fonti, nell'ordine: le tassonomie storiche in tabella propria (`activity`, `theme`,
 * `poi-type`), le zone (`where`, da `properties.taxonomy_where`) e le tassonomie di
 * `taxonomy_terms` accese per l'app e per quel tipo di contenuto. Una tassonomia senza termini
 * non compare. Le chiavi vecchie (`taxonomy`, `taxonomy_where`, `taxonomyWheres`,
 * `taxonomyIdentifiers`) restano identiche: questa le sostituisce, non le cambia.
 *
 * Un'istanza memorizza le tassonomie accese per (app, tipo di contenuto): `pois.geojson` ne usa una
 * per tutto il file, così non si interroga il database una volta per POI.
 */
class DetailTaxonomiesService
{
    /**
     * Tab di una tassonomia che non ne dichiara uno: lo stesso default della sezione delle
     * classificazioni in Nova (`BuildsTaxonomyFields::taxonomyTabs()`), dove finiscono anche le
     * zone.
     */
    public const DEFAULT_TAB = 'General';

    public const WHERE_TAXONOMY = 'where';

    /**
     * Le tassonomie storiche (tabella propria) che entrano in `properties.taxonomies` e in
     * `TAXONOMY_LABELS` del config.json, con la chiave con cui ci entrano. `relation` è la
     * relazione sul contenuto (`null` per le zone, che stanno in `properties.taxonomy_where`);
     * `label` è la chiave di traduzione del nome, la stessa di Nova (`null` = il `label` di
     * `config('wm-package.legacy_taxonomies')`). `when` e `target` non ci sono: la prima non è più
     * usata, la seconda è vuota e le custom stanno in `taxonomy_terms`.
     *
     * Le chiavi sono definitive e in kebab-case, lo stesso formato degli identifier di
     * `taxonomy_terms` (`TaxonomyTerm::TAXONOMY_IDENTIFIER_FORMAT`): quando una storica passerà in
     * `taxonomy_terms` prenderà come identifier la sua chiave, e i frontend non vedranno niente
     * cambiare. Fino ad allora la chiave è riservata (`TaxonomyTerm::RESERVED_TAXONOMY_IDENTIFIERS`),
     * e la riserva cade con la voce di qui.
     *
     * @var array<string, array{relation: string|null, label: string|null}>
     */
    public const LEGACY_TAXONOMIES = [
        'activity' => ['relation' => 'taxonomyActivities', 'label' => null],
        'theme' => ['relation' => 'taxonomyThemes', 'label' => 'Themes'],
        'poi-type' => ['relation' => 'taxonomyPoiTypes', 'label' => 'Poi Types'],
        self::WHERE_TAXONOMY => ['relation' => null, 'label' => 'Zones'],
    ];

    private static ?bool $taxonomyTermsTablesExist = null;

    /** @var array<string, list<int>> */
    private array $enabledTaxonomies = [];

    /**
     * Le tassonomie di un contenuto.
     *
     * @param  string  $modelScope  il tipo di contenuto per l'ambito di `app_taxonomy_term.models`:
     *                              `EcTrack`, `EcPoi`
     * @param  int|null  $appId  l'app di cui valgono le tassonomie accese: per una traccia la sua, per
     *                           un POI di `pois.geojson` quella del file (un POI globale può essere
     *                           di un'altra app)
     * @return array<string, list<array<string, mixed>>>
     */
    public function build(Model $model, string $modelScope, ?int $appId): array
    {
        $taxonomies = [];

        foreach (self::LEGACY_TAXONOMIES as $key => $legacy) {
            $relation = $legacy['relation'];
            if ($relation === null || ! method_exists($model, $relation)) {
                continue;
            }
            $tab = self::legacyTaxonomyTab($key);

            // Le relazioni storiche non hanno un ordine: si fissa per id, così il file non cambia
            // fra una rigenerazione e l'altra senza che sia cambiato niente.
            /** @var Taxonomy $term */
            foreach ($model->getRelationValue($relation)->sortBy('id') as $term) {
                // La colonna è nullable: un termine senza identifier non si può usare come valore.
                $identifier = $term->getAttribute('identifier');
                if ($identifier === null || $identifier === '') {
                    continue;
                }
                $taxonomies[$key][] = self::entry((string) $identifier, $term->getTranslations('name'), $tab, [
                    'description' => $term->getTranslations('description'),
                    'excerpt' => $term->getTranslations('excerpt'),
                    'icon' => $term->getAttribute('icon'),
                ]);
            }
        }

        foreach ($this->wheres($model) as $where) {
            $taxonomies[self::WHERE_TAXONOMY][] = $where;
        }

        foreach ($this->taxonomyTerms($model, $modelScope, $appId) as $identifier => $terms) {
            $taxonomies[$identifier] = $terms;
        }

        return $taxonomies;
    }

    /**
     * Come `build()`, pronto per il JSON: vuota è un oggetto, perché un array PHP vuoto
     * diventerebbe `[]`.
     */
    public function buildForJson(Model $model, string $modelScope, ?int $appId): array|\stdClass
    {
        $taxonomies = $this->build($model, $modelScope, $appId);

        return $taxonomies === [] ? new \stdClass : $taxonomies;
    }

    /**
     * Il tab di una tassonomia storica: quello di `legacy_taxonomies`, come in Nova, altrimenti
     * il default.
     */
    public static function legacyTaxonomyTab(string $key): string
    {
        $tab = self::legacyTaxonomyConfig($key)['tab'] ?? null;

        return is_string($tab) && $tab !== '' ? $tab : self::DEFAULT_TAB;
    }

    /**
     * La chiave di traduzione del nome di una tassonomia storica, la stessa di Nova.
     */
    public static function legacyTaxonomyLabel(string $key): string
    {
        $label = self::LEGACY_TAXONOMIES[$key]['label'] ?? null;

        return $label ?? (string) (self::legacyTaxonomyConfig($key)['label'] ?? $key);
    }

    /**
     * Il tab che una tassonomia di `taxonomy_terms` dichiara in `properties->tab`, o il default.
     */
    public static function taxonomyTab(TaxonomyTerm $taxonomy): string
    {
        $tab = data_get($taxonomy->properties, 'tab');

        return is_string($tab) && $tab !== '' ? $tab : self::DEFAULT_TAB;
    }

    /**
     * Le tre tabelle di oc:8910 ci sono? Si chiede una volta per processo: le tabelle non
     * spariscono mentre un worker gira, e senza memoria ogni contenuto costerebbe tre query sullo
     * schema. Su uno shard senza quelle migration si controlla prima, perché un `catch` sulla query
     * dentro una transazione PostgreSQL la lascerebbe abortita.
     */
    public static function hasTaxonomyTermsTables(): bool
    {
        return self::$taxonomyTermsTablesExist ??= Schema::hasTable('taxonomy_terms')
            && Schema::hasTable('taxonomy_termables')
            && Schema::hasTable('app_taxonomy_term');
    }

    /**
     * La voce di `config('wm-package.legacy_taxonomies')` di una tassonomia storica, se c'è: la
     * stessa che Nova usa per tab e nome.
     *
     * @return array<string, mixed>|null
     */
    private static function legacyTaxonomyConfig(string $key): ?array
    {
        $relation = self::LEGACY_TAXONOMIES[$key]['relation'] ?? null;
        if ($relation === null) {
            return null;
        }

        return collect((array) config('wm-package.legacy_taxonomies', []))
            ->first(fn ($legacy) => is_array($legacy) && ($legacy['relation'] ?? null) === $relation);
    }

    /**
     * Un termine: `identifier`, `name` e `tab` sempre, gli altri campi solo se hanno un valore,
     * così il file non si riempie di `null`. I campi tecnici hanno nomi senza `_` (`admin_level`,
     * non `_admin_level`).
     *
     * @param  array<string, string>  $name
     * @param  array<string, mixed>  $optional
     * @return array<string, mixed>
     */
    private static function entry(string $identifier, array $name, string $tab, array $optional = []): array
    {
        return [
            'identifier' => $identifier,
            // Senza traduzioni resta un oggetto: un array PHP vuoto diventerebbe `[]` in JSON.
            'name' => $name === [] ? new \stdClass : $name,
            'tab' => $tab,
            ...array_filter($optional, fn ($value) => $value !== null && $value !== '' && $value !== []),
        ];
    }

    /**
     * Le zone: quelle di `properties.taxonomy_where` che mostra l'app del contenuto, filtrate con
     * lo stesso servizio e la stessa app di `applyTaxonomyWhereDisplay()`, che sulle uscite si
     * applica dopo: senza, `taxonomies.where` avrebbe zone che `taxonomy_where` nella stessa feature
     * non ha. Del nome si tengono solo le lingue: le chiavi che cominciano con `_` sono tecniche.
     *
     * @return list<array<string, mixed>>
     */
    private function wheres(Model $model): array
    {
        $properties = $model->getAttribute('properties');
        $wheres = is_array($properties) ? ($properties['taxonomy_where'] ?? []) : [];
        if (! is_array($wheres) || $wheres === []) {
            return [];
        }

        $service = app(TaxonomyWhereDisplayService::class);
        $ownerAppId = $model->getAttribute('app_id');
        $filtered = $service->filter($wheres, $service->selectedCategoriesForApp($ownerAppId === null ? null : (int) $ownerAppId));

        // Dalla zona più ampia alla più piccola, senza livello per prime: lo stesso ordine di
        // `taxonomyWheres` (`TaxonomyWhereDisplayService::orderedNames()`). L'ordinamento di PHP è
        // stabile, quindi a parità di livello resta l'ordine salvato.
        uasort($filtered, function ($a, $b) {
            $levelA = is_array($a) && is_numeric($a['_admin_level'] ?? null) ? (int) $a['_admin_level'] : null;
            $levelB = is_array($b) && is_numeric($b['_admin_level'] ?? null) ? (int) $b['_admin_level'] : null;

            return [$levelA !== null, $levelA] <=> [$levelB !== null, $levelB];
        });

        $tab = self::legacyTaxonomyTab(self::WHERE_TAXONOMY);
        $out = [];
        foreach ($filtered as $identifier => $entry) {
            $name = array_filter(
                is_array($entry) ? $entry : [],
                fn ($value, $key) => is_string($key) && ! str_starts_with($key, '_') && is_string($value) && $value !== '',
                ARRAY_FILTER_USE_BOTH
            );
            $out[] = self::entry((string) $identifier, $name, $tab, [
                'admin_level' => is_array($entry) && is_numeric($entry['_admin_level'] ?? null) ? (int) $entry['_admin_level'] : null,
                'source' => is_array($entry) && is_string($entry['_source'] ?? null) ? $entry['_source'] : null,
            ]);
        }

        return $out;
    }

    /**
     * I termini di `taxonomy_terms`, raggruppati per identifier della tassonomia (letta dal padre
     * vivo, come `termsForSearch()`), con il tab della tassonomia. Il filtro è `forAppAndModel()`:
     * conta anche l'ambito per tipo di contenuto, come nel form Nova.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function taxonomyTerms(Model $model, string $modelScope, ?int $appId): array
    {
        if (! method_exists($model, 'taxonomyTerms') || ! self::hasTaxonomyTermsTables()) {
            return [];
        }

        $enabled = $this->enabledTaxonomies[$appId.'|'.$modelScope] ??= TaxonomyTerm::query()->taxonomies()
            ->forAppAndModel($appId, $modelScope)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $grouped = [];
        foreach ($model->getRelationValue('taxonomyTerms') as $term) {
            $taxonomy = $term->parent;
            if ($taxonomy === null || ! in_array((int) $taxonomy->id, $enabled, true)) {
                continue;
            }

            $grouped[$taxonomy->identifier][] = self::entry(
                (string) $term->identifier,
                $term->getTranslations('name'),
                self::taxonomyTab($taxonomy),
                [
                    'description' => $term->getTranslations('description'),
                    'excerpt' => $term->getTranslations('excerpt'),
                    'icon' => $term->icon,
                    'sort_order' => $term->sort_order,
                    'pivot_properties' => $term->pivot->properties ?? null,
                ]
            );
        }

        return $grouped;
    }
}
