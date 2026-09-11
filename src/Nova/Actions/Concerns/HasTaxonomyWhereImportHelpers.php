<?php

namespace Wm\WmPackage\Nova\Actions\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

trait HasTaxonomyWhereImportHelpers
{
    /**
     * Risolve l'App da usare per l'import: se esiste una sola App non serve
     * selezione esplicita, altrimenti richiede `app_id` dal campo Select.
     *
     * @return App|string L'App risolta, oppure il messaggio di errore da
     *                    passare ad Action::danger() se la risoluzione fallisce.
     */
    protected function resolveApp(ActionFields $fields): App|string
    {
        $apps = App::all();
        if ($apps->count() === 1) {
            return $apps->first();
        }

        $appId = $fields->get('app_id');
        if (! $appId) {
            return "Seleziona un'App.";
        }

        $app = App::find($appId);
        if (! $app) {
            return 'App non trovata.';
        }

        return $app;
    }

    /**
     * Gate condiviso per la sorgente geohub — richiamato sia da handle()
     * (contesto: prima modale, costruzione del payload) sia dal controller
     * del nuovo endpoint HTTP (contesto: esecuzione reale dell'import), per
     * evitare due controlli indipendenti che potrebbero disallinearsi in
     * futuro.
     */
    protected function isGeohubSourceAllowed(?Authenticatable $user): bool
    {
        return RolesAndPermissionsService::allowsUser($user);
    }

    /**
     * Risolve la riga "apps" su GeoHub per l'App locale selezionata.
     *
     * @return object|string La riga GeoHub, oppure il messaggio di errore da
     *                       passare ad Action::danger() se la risoluzione fallisce.
     */
    protected function resolveGeohubApp(App $app): object|string
    {
        if (empty($app->geohub_id)) {
            return 'App non collegata a GeoHub (geohub_id assente).';
        }

        $geohubApp = DB::connection('geohub')->table('apps')->where('id', $app->geohub_id)->first();
        if (! $geohubApp || empty($geohubApp->user_id)) {
            return 'Impossibile risolvere lo user GeoHub per questa App.';
        }

        return $geohubApp;
    }

    /**
     * Query condivisa: taxonomy_where GeoHub senza admin_level collegate ai
     * contenuti (EcPoi/EcTrack/Layer) dell'App — richiamata sia per popolare
     * il pannello di selezione sia dentro handleGeohub() per i dati completi.
     *
     * @return array<int, object{id: int, name: mixed, identifier: ?string}>
     */
    protected function fetchGeohubCandidateWheres(App $app, object $geohubApp): array
    {
        return DB::connection('geohub')->select(<<<'SQL'
            select distinct tw.id, tw.name, tw.identifier
            from taxonomy_wheres tw
            join taxonomy_whereables twa on tw.id = twa.taxonomy_where_id
            where tw.admin_level is null
              and (
                (twa.taxonomy_whereable_type like '%EcPoi%' and twa.taxonomy_whereable_id in (select id from ec_pois where user_id = ?))
                or (twa.taxonomy_whereable_type like '%EcTrack%' and twa.taxonomy_whereable_id in (select id from ec_tracks where user_id = ?))
                or (twa.taxonomy_whereable_type like '%Layer%' and twa.taxonomy_whereable_id in (select id from layers where app_id = ?))
              )
            SQL, [$geohubApp->user_id, $geohubApp->user_id, $app->geohub_id]);
    }

    /**
     * Costruisce le righe pronte per il rendering della seconda modale
     * (checkbox list, tutte pre-selezionate) — sostituisce
     * buildGeohubWhereOptions() del follow-up precedente (campo Nova
     * BooleanGroup, ora rimosso). Stessa logica di etichettatura (nome
     * tradotto + identifier + "già importata"), ma il chiamante è handle()
     * stesso: App e riga GeoHub sono già risolte e il gate già verificato
     * PRIMA di arrivare qui, quindi questo metodo non ripete alcun controllo.
     *
     * @return array<int, array{id: string, label: string, checked: bool}>
     */
    protected function buildGeohubWhereSelectionPayload(App $app, object $geohubApp): array
    {
        $rows = $this->fetchGeohubCandidateWheres($app, $geohubApp);
        if (count($rows) === 0) {
            return [];
        }

        // Stesso motivo del ciclo precedente: pluck('properties->geohub_id')
        // sul query builder non funziona su Postgres senza alias esplicito
        // (la colonna estratta si chiama "?column?", non "properties->geohub_id").
        $importedGeohubIds = TaxonomyWhere::whereNotNull('properties->geohub_id')
            ->get()
            ->pluck('properties.geohub_id')
            ->map(fn ($v) => (string) $v)
            ->all();

        $payload = [];
        foreach ($rows as $row) {
            $name = is_string($row->name) ? (json_decode($row->name, true) ?? $row->name) : $row->name;
            $label = is_array($name) ? ($name['it'] ?? $name['en'] ?? (reset($name) ?: $row->identifier)) : $name;
            $label = $label.' — '.$row->identifier;

            if (in_array((string) $row->id, $importedGeohubIds, true)) {
                $label .= ' ('.__('già importata').')';
            }

            $payload[] = [
                'id' => (string) $row->id,
                'label' => $label,
                'checked' => true,
            ];
        }

        return $payload;
    }

    /**
     * Esegue l'import vero e proprio (creazione/aggiornamento/collision
     * handling + dispatch batch geometria) per il sottoinsieme di where
     * selezionato dall'admin nella seconda modale. Unico chiamante reale: il
     * controller HTTP del nuovo endpoint (handle()/handleGeohub() non
     * eseguono più alcuna scrittura). Ri-deriva autonomamente il set
     * autoritativo delle where candidate — non si fida ciecamente di
     * $selectedIds ricevuti dal client — e vi interseca la selezione: un id
     * fuori dal set candidato per questa App viene semplicemente escluso, non
     * causa un errore.
     *
     * @param  array<int, string|int>  $selectedIds
     * @return array{created: int, updated: int}|null Null se l'intersezione
     *                                                con il set candidato è vuota.
     */
    protected function executeGeohubImport(App $app, object $geohubApp, array $selectedIds): ?array
    {
        $rows = $this->fetchGeohubCandidateWheres($app, $geohubApp);

        $selectedIds = array_map('strval', $selectedIds);
        $rows = array_values(array_filter(
            $rows,
            fn ($row) => in_array((string) $row->id, $selectedIds, true)
        ));

        if (count($rows) === 0) {
            return null;
        }

        $created = 0;
        $updated = 0;
        $geometryJobs = [];

        foreach ($rows as $row) {
            $name = is_string($row->name) ? (json_decode($row->name, true) ?? $row->name) : $row->name;

            $existing = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $row->id])->first();

            if (! $existing && $row->identifier) {
                $existing = TaxonomyWhere::where('identifier', $row->identifier)->first();
            }

            $properties = [
                'geohub_id' => $row->id,
                'source' => 'geohub',
                'admin_level' => null,
            ];

            if ($existing) {
                $existing->update([
                    'name' => $name,
                    'properties' => array_merge($existing->properties ?? [], $properties),
                ]);
                $this->assignTaxonomyUserFromApp($existing, $app);
                $geometryJobs[] = new CopyTaxonomyWhereGeometryFromGeohubJob($existing->id, $row->id);
                $updated++;

                continue;
            }

            $taxonomyWhere = new TaxonomyWhere([
                'name' => $name,
                'properties' => $properties,
            ]);
            $taxonomyWhere->identifier = $row->identifier
                ? $taxonomyWhere->withCollisionCounter($row->identifier)
                : null;
            $taxonomyWhere->save();

            $this->assignTaxonomyUserFromApp($taxonomyWhere, $app);
            $geometryJobs[] = new CopyTaxonomyWhereGeometryFromGeohubJob($taxonomyWhere->id, $row->id);
            $created++;
        }

        // Stesso motivo dei cicli precedenti: syncTracksTaxonomyWhere() deve
        // partire solo a copia geometrie completata, non subito dopo il
        // dispatch asincrono dei job CopyTaxonomyWhereGeometryFromGeohubJob.
        Bus::batch($geometryJobs)
            ->then(function () {
                SyncTaxonomyWhereTracksJob::dispatch();
            })
            ->dispatch();

        return ['created' => $created, 'updated' => $updated];
    }

    private function assignTaxonomyUserFromApp(TaxonomyWhere $taxonomyWhere, App $app): void
    {
        if (! Schema::hasColumn($taxonomyWhere->getTable(), 'user_id')) {
            return;
        }

        if (empty($app->user_id)) {
            return;
        }

        $taxonomyWhere->forceFill(['user_id' => $app->user_id])->saveQuietly();
    }

    /**
     * Dispatcha il sync locale (via ST_Intersects) delle track esistenti sulle
     * taxonomy_where appena importate/aggiornate, e appende il contatore al
     * messaggio finale — stesso comportamento per tutte e tre le sorgenti.
     */
    protected function finalizeWithTracksSync(string $message): string
    {
        $tracksSynced = GeometryComputationService::make()->syncTracksTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );

        return $message." Sync taxonomy_where su {$tracksSynced} tracks avviata.";
    }
}
