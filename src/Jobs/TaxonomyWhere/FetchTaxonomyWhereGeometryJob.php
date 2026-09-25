<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

class FetchTaxonomyWhereGeometryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Lingue della piattaforma, stesse di resources/lang/*.json — oc:8588. Costante di classe
     * (non di trait: PHP >8.1, le const nei trait esistono solo da 8.2, vedi CLAUDE.md).
     */
    private const PLATFORM_LANGUAGES = ['it', 'en', 'de', 'fr', 'es'];

    public function __construct(public int $taxonomyWhereId) {}

    public function handle(OsmfeaturesClient $client): void
    {
        $taxonomyWhere = TaxonomyWhere::findOrFail($this->taxonomyWhereId);

        $osmfeaturesId = $taxonomyWhere->getOsmfeaturesId();

        if (empty($osmfeaturesId)) {
            Log::warning('TaxonomyWhere non ha osmfeatures_id, skip geometry fetch', [
                'taxonomy_where_id' => $this->taxonomyWhereId,
            ]);

            return;
        }

        $detail = $client->getAdminAreaDetail($osmfeaturesId);

        $this->syncNameFromDetail($taxonomyWhere, $detail, $osmfeaturesId);

        if (empty($detail['geometry'])) {
            Log::warning('TaxonomyWhere geometry not available from OSMFeatures', [
                'taxonomy_where_id' => $this->taxonomyWhereId,
                'osmfeatures_id' => $osmfeaturesId,
            ]);

            return;
        }

        DB::statement(
            'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
            [$detail['geometry'], $taxonomyWhere->id]
        );
    }

    /**
     * Regola voluta dal dev durante il test manuale di oc:8588: il
     * dettaglio OSMFeatures e' la fonte piu' completa per i nomi, quindi sostituisce sempre le 5
     * traduzioni delle lingue della piattaforma (self::PLATFORM_LANGUAGES), non solo quando manca
     * un nome "affidabile" come faceva la versione precedente. Le lingue gia' presenti fuori da
     * quelle 5 (es. 'sc' da GeoHub) non vengono toccate: si scrive solo con setTranslation() sui
     * singoli codici lingua della piattaforma.
     *
     * Per ciascuna lingua della piattaforma:
     * 1. usa detail['names'][<lang>] (da osm_tags 'name:<lang>') se presente;
     * 2. per 'it', se manca, usa il nome base (detail['name']);
     * 3. le lingue ancora senza nome ricevono il nome 'it' gia' risolto, o - se anche 'it' manca -
     *    l'unico nome disponibile tra quelli risolti ai punti 1-2.
     *
     * Se il dettaglio non ha nessun nome (ne' base ne' traduzioni), il nome esistente non viene
     * toccato: non si scrive mai l'id OSM come nome (regola invariata rispetto alla versione
     * precedente).
     *
     * Fix di review (oc:8588): la sincronizzazione avviene SOLO per record la cui
     * sorgente e' osmfeatures (`properties['source'] === 'osmfeatures'`) o priva di sorgente
     * (record legacy pre-oc:8469/b6ddbda6: prima di quel fix ne' l'import osmfeatures ne' il
     * modello stampavano `properties['source']` — la migration di backfill dell'identifier di
     * quel fix usa esplicitamente `COALESCE(properties->>'source', '')`, prova diretta che
     * esistono/esistevano righe osmfeatures senza `source` in produzione). Un record con
     * `source` esplicito e diverso (es. `'geohub'`) puo' comunque avere un `osmfeatures_id`
     * "residuo": `HasTaxonomyWhereImportHelpers::executeGeohubImport()` fa
     * `array_merge($existing->properties ?? [], $properties)` senza rimuovere una eventuale
     * chiave `osmfeatures_id` gia' presente su un record trovato per `identifier`/`geohub_id`
     * che era stato creato in precedenza dall'import osmfeatures — il nome di un record del
     * genere e' curato da GeoHub, non deve essere sovrascritto qui. Il job resta dispatchabile
     * per questi record da `RetryTaxonomyWhereGeometryFetch::dispatchGeometryJob()` (controlla
     * solo `getOsmfeaturesId()`, non `source`): l'aggiornamento della geometria (sotto, fuori da
     * questo metodo) continua invariato per ogni sorgente, solo il nome viene protetto qui.
     */
    protected function syncNameFromDetail(TaxonomyWhere $taxonomyWhere, array $detail, string $osmfeaturesId): void
    {
        $source = $taxonomyWhere->getSource();
        if ($source !== null && $source !== '' && $source !== TaxonomyWhereDisplayService::OSMFEATURES_SOURCE) {
            return;
        }

        $baseName = $this->cleanDetailName($detail['name'] ?? null, $osmfeaturesId);
        $names = is_array($detail['names'] ?? null) ? $detail['names'] : [];

        $resolved = [];
        foreach (self::PLATFORM_LANGUAGES as $lang) {
            $value = $this->cleanDetailName($names[$lang] ?? null, $osmfeaturesId);
            if ($value !== null) {
                $resolved[$lang] = $value;
            }
        }

        if (! isset($resolved['it']) && $baseName !== null) {
            $resolved['it'] = $baseName;
        }

        if (empty($resolved)) {
            return;
        }

        // Se manca sia 'it' sia il nome base e sono risolte piu' traduzioni non italiane (es. solo
        // 'de' e 'fr'), il fallback usato per le lingue ancora senza nome e' la PRIMA risolta
        // nell'ordine di iterazione di PLATFORM_LANGUAGES (qui 'de' precede 'fr'): reset($resolved)
        // punta al primo elemento inserito nel loop sopra, che segue quell'ordine. Non e' una
        // scelta "a caso" tra le traduzioni disponibili, e' deterministica sull'ordine della
        // costante di classe.
        $fallback = $resolved['it'] ?? reset($resolved);

        foreach (self::PLATFORM_LANGUAGES as $lang) {
            if (! isset($resolved[$lang])) {
                $resolved[$lang] = $fallback;
            }
        }

        foreach ($resolved as $lang => $value) {
            $taxonomyWhere->setTranslation('name', $lang, $value);
        }

        $taxonomyWhere->save();
    }

    /**
     * Un nome vuoto o coincidente con l'id OSM non e' un nome valido (segnaposto). Il client
     * aggiornato non ripiega piu' sull'id, ma la guardia resta come protezione difensiva.
     */
    private function cleanDetailName(mixed $value, string $osmfeaturesId): ?string
    {
        if (! is_string($value) || $value === '' || $value === $osmfeaturesId) {
            return null;
        }

        return $value;
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FetchTaxonomyWhereGeometryJob failed after all retries', [
            'taxonomy_where_id' => $this->taxonomyWhereId,
            'error' => $e->getMessage(),
        ]);
    }
}
