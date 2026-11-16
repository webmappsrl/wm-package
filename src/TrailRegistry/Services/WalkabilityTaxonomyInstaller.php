<?php

namespace Wm\WmPackage\TrailRegistry\Services;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Crea la famiglia della percorribilita' e i suoi termini, e la accende dove
 * serve perche' il dato esca davvero (oc:8989).
 *
 * Accendere l'interruttore del Catasto e migrare deve bastare: per questo gira
 * da una migration del dominio e non da un comando che qualcuno deve
 * ricordarsi di lanciare.
 *
 * **Creare la famiglia non basta.** `DetailTaxonomiesService` scarta i termini
 * delle famiglie che l'app non ha acceso, e lo stesso vale per
 * l'indicizzazione: senza il legame in `app_taxonomy_term` il dato si
 * inserisce, si vede in Nova — dove un fallback mostra le famiglie che nessuna
 * app accende — e non esce in API, senza un errore da nessuna parte.
 *
 * Il nome della famiglia lo decide lo shard con
 * `wm-package.features.trail_registry.walkability_taxonomy`: qui si legge, non
 * si scrive. Gli identifier dei termini ne derivano, cosi' uno shard che
 * cambia il nome non si ritrova figli con il prefisso di un altro.
 */
class WalkabilityTaxonomyInstaller
{
    /**
     * Il tab della sezione delle classificazioni in cui finisce la famiglia: lo
     * stesso della validazione, perche' chi guarda lo stato di un sentiero vuole
     * vederli insieme e vicino allo storico. E' una chiave, non un'etichetta:
     * Nova la traduce con `__()`.
     */
    public const TAB = 'Status';

    /**
     * I quattro stati, in ordine: prima l'assenza di informazione, poi dal
     * peggiore al migliore, come `validation-status`.
     *
     * @var array<int, array{suffix: string, it: string, en: string, icon: string}>
     */
    private const TERMS = [
        ['suffix' => 'unknown', 'it' => 'Sconosciuta', 'en' => 'Unknown', 'icon' => 'wm-icon-question'],
        ['suffix' => 'not-walkable', 'it' => 'Non percorribile', 'en' => 'Not walkable', 'icon' => 'wm-icon-close'],
        ['suffix' => 'partially-walkable', 'it' => 'Parzialmente percorribile', 'en' => 'Partially walkable', 'icon' => 'wm-icon-alert'],
        ['suffix' => 'walkable', 'it' => 'Percorribile', 'en' => 'Walkable', 'icon' => 'wm-icon-check'],
    ];

    public function install(): void
    {
        $identifier = $this->taxonomyIdentifier();

        $family = TaxonomyTerm::query()->firstOrNew(['identifier' => $identifier]);
        $family->fill([
            'name' => ['it' => 'Percorribilità', 'en' => 'Walkability'],
            'single_choice' => true,
            'sort_order' => 0,
            // La percorribilita' ha un tab suo nella sezione delle
            // classificazioni: il campo che la cambia non e' il `Choice`
            // generico ma quello che passa dal service, e tenerla nel tab
            // generale accanto alle altre famiglie la farebbe sembrare una
            // classificazione come le altre.
            'properties' => array_merge($family->properties ?? [], ['tab' => self::TAB]),
        ]);
        // `is_system` non e' fillable: un `fill()` lo scarterebbe in silenzio,
        // e le app create dopo non riceverebbero la famiglia.
        $family->is_system = true;
        $family->save();

        foreach (self::TERMS as $position => $term) {
            $child = TaxonomyTerm::query()->firstOrNew([
                'identifier' => "{$identifier}-{$term['suffix']}",
            ]);
            $child->fill([
                'parent_id' => $family->id,
                'name' => ['it' => $term['it'], 'en' => $term['en']],
                'icon' => $term['icon'],
                'sort_order' => $position,
            ])->save();
        }

        $this->enableForExistingApps($family);
    }

    /**
     * Le app che gia' esistono non passano da AppObserver, che accende le
     * famiglie di sistema solo alla creazione: vanno accese qui, e solo per il
     * modello delle tracce.
     */
    private function enableForExistingApps(TaxonomyTerm $family): void
    {
        foreach (App::query()->get() as $app) {
            // `syncTaxonomiesForModel` e' il punto che il package usa gia' per
            // questa tabella: tiene insieme i due elenchi (tracce e POI) senza
            // che uno cancelli l'altro, e serializza `models`. Scrivere il
            // pivot a mano li' farebbe a pezzi.
            $already = $app->taxonomies()->pluck('taxonomy_terms.id')->all();
            $app->syncTaxonomiesForModel('EcTrack', array_values(array_unique([...$already, $family->id])));
        }
    }

    private function taxonomyIdentifier(): string
    {
        /** @var string $identifier */
        $identifier = config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status');

        return $identifier;
    }
}
