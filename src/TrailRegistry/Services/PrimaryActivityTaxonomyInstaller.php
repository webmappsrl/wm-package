<?php

namespace Wm\WmPackage\TrailRegistry\Services;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * Crea la famiglia dell'attivita' primaria di un sentiero e i suoi termini, e
 * la accende dove serve perche' il dato esca davvero (oc:9038).
 *
 * Accendere l'interruttore del Catasto e migrare deve bastare: per questo gira
 * da una migration del dominio e non da un comando che qualcuno deve
 * ricordarsi di lanciare.
 *
 * **Creare la famiglia non basta.** `DetailTaxonomiesService` scarta i termini
 * delle famiglie che l'app non ha acceso, e lo stesso vale per
 * l'indicizzazione: senza il legame in `app_taxonomy_term` il dato si
 * inserisce, si vede in Nova e non esce in API, senza un errore da nessuna
 * parte.
 *
 * Il nome della famiglia lo decide lo shard con
 * `wm-package.features.trail_registry.primary_activity_taxonomy`: qui si legge,
 * non si scrive. Gli identifier dei termini ne derivano, cosi' uno shard che
 * cambia il nome non si ritrova figli con il prefisso di un altro.
 *
 * Due differenze volute rispetto a `WalkabilityTaxonomyInstaller`:
 * - i termini non sono una costante ma le attivita' dello schema
 *   (`ApplicationSchema::activities()`), cosi' uno shard che lo ridefinisce
 *   ottiene le sue. `trail`, `itinerary` e `walk` stanno in
 *   `taxonomy_activities` ma attivita' non sono, e non hanno un termine;
 * - il `name` del termine si copia dalla `TaxonomyActivity` con lo stesso
 *   identifier: la stessa traccia mostra gia' quell'attivita' con quel nome, e
 *   un nome fisso nel package darebbe alla primaria un nome diverso. Se
 *   l'attivita' non c'e' a database vale l'identifier.
 */
class PrimaryActivityTaxonomyInstaller
{
    /**
     * Il tab della sezione delle classificazioni in cui finisce la famiglia:
     * accanto alle attivita' di cui e' una scelta. E' una chiave, non
     * un'etichetta: Nova la traduce con `__()`.
     */
    public const TAB = 'Activities';

    public function install(): void
    {
        $identifier = $this->taxonomyIdentifier();

        $family = TaxonomyTerm::query()->firstOrNew(['identifier' => $identifier]);
        $family->fill([
            'name' => ['it' => 'Attività primaria', 'en' => 'Primary activity'],
            'single_choice' => true,
            // Prima delle difficolta', che stanno a 0: nel tab le famiglie
            // seguono `sort_order` e poi `id`, e con lo stesso ordine la
            // primaria finirebbe ultima perche' creata dopo. La sua voce va
            // letta accanto alle attivita' di cui e' una scelta, non in fondo
            // a sei scale di difficolta'.
            'sort_order' => -1,
            'properties' => array_merge($family->properties ?? [], ['tab' => self::TAB]),
        ]);
        // Senza `is_system` le app create dopo non riceverebbero la famiglia:
        // `AppObserver` accende alla creazione solo le famiglie di sistema.
        $family->is_system = true;
        $family->save();

        foreach (app(ApplicationSchema::class)->activities() as $position => $suffix) {
            $child = TaxonomyTerm::query()->firstOrNew([
                'identifier' => "{$identifier}-{$suffix}",
            ]);
            $child->fill([
                'parent_id' => $family->id,
                'name' => $this->activityName($suffix),
                'sort_order' => $position,
            ])->save();
        }

        $this->enableForExistingApps($family);
    }

    /**
     * Le traduzioni del nome dell'attivita' con questo identifier, copiate
     * cosi' come sono (tutte le lingue); se a database non c'e', l'identifier
     * stesso. Si legge `getTranslations()` e non `->name`: con
     * `HasTranslations` l'accessor torna solo la stringa della lingua
     * corrente.
     *
     * @return array<string, string>
     */
    private function activityName(string $suffix): array
    {
        $names = TaxonomyActivity::query()->where('identifier', $suffix)->first()?->getTranslations('name');

        return $names ?: ['it' => $suffix, 'en' => $suffix];
    }

    /**
     * Le app che gia' esistono non passano da AppObserver, che accende le
     * famiglie di sistema solo alla creazione: vanno accese qui, e solo per il
     * modello delle tracce.
     */
    private function enableForExistingApps(TaxonomyTerm $family): void
    {
        foreach (App::query()->get() as $app) {
            // `syncTaxonomiesForModel('EcTrack', $ids)` toglie `EcTrack` da OGNI
            // tassonomia dell'app e lo rimette solo a quelle in `$ids`; una
            // tassonomia che resta senza modelli viene staccata dall'app. Per
            // questo la lista giusta non e' ne' «solo la famiglia» ne' «tutte»:
            // - passando solo la famiglia, le tassonomie gia' accese per le
            //   tracce (difficolta', validazione, percorribilita'...) perdono
            //   `EcTrack`, restano vuote e vengono staccate dall'app;
            // - passando tutte, anche una accesa solo per `EcPoi` riceverebbe
            //   `EcTrack`, e `models` diventerebbe null («tutti i contenuti»).
            // Si passano quindi quelle che hanno gia' `EcTrack` (o `models`
            // nullo, cioe' tutti i contenuti: ripassata viene esplosa, perde e
            // riprende `EcTrack` e torna null), piu' la famiglia nuova. Una
            // accesa solo per `EcPoi` non e' passata e resta com'e'.
            $ids = $app->taxonomies()->get()
                ->filter(function (TaxonomyTerm $taxonomy) {
                    $models = $taxonomy->pivot->models ?? null;
                    $models = is_string($models) ? json_decode($models, true) : $models;

                    return ! is_array($models) || $models === [] || in_array('EcTrack', $models, true);
                })
                ->pluck('id')
                ->all();

            $app->syncTaxonomiesForModel('EcTrack', array_values(array_unique([...$ids, $family->id])));
        }
    }

    private function taxonomyIdentifier(): string
    {
        /** @var string $identifier */
        $identifier = config('wm-package.features.trail_registry.primary_activity_taxonomy', 'primary-activity');

        return $identifier;
    }
}
