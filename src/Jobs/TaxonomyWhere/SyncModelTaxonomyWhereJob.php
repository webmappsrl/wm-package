<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

class SyncModelTaxonomyWhereJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Resta come alias, usata dai test (oc:8588): la costante di riferimento e'
     * `TaxonomyWhereDisplayService::OSMFEATURES_SOURCE`.
     */
    public const SOURCE_OSMFEATURES = TaxonomyWhereDisplayService::OSMFEATURES_SOURCE;

    public function __construct(protected GeometryModel $model) {}

    public function handle(GeometryComputationService $service, OsmfeaturesClient $osmfeaturesClient): void
    {
        // preserveOnNoMatch di default (null) si deriva da $modelId: scoped qui, quindi false —
        // il sync locale azzera se non trova corrispondenze. Il conteggio ritornato dice se ha
        // trovato un match *in questo giro*, indipendentemente da cosa c'era scritto prima
        // (oc:8487, ripresa 2026-09-23).
        $synced = $service->syncTaxonomyWhere(get_class($this->model), $this->model->id);
        if ($synced > 0) {
            return;
        }

        $mapped = [];
        $geojson = $this->model->getGeojson();
        if ($geojson !== null) {
            // Mappa il formato grezzo di `OsmfeaturesClient::getWheresByGeojson()`
            // (`{whereId: {lang: label, ..., _admin_level: int}}`) alla forma vecchia scritta
            // anche dal calcolo SQL locale (`{whereId: {<lingue>, _admin_level, _source}}`,
            // oc:8588). Scarta le entry senza alcuna traduzione del nome: un'area amministrativa
            // con solo `admin_level` e nessun tag `name*` produrrebbe un'etichetta vuota, meno
            // utile di nessuna entry.
            $mapped = app(TaxonomyWhereDisplayService::class)
                ->fromOsmfeatures($osmfeaturesClient->getWheresByGeojson($geojson));
        }

        // Se né il sync locale né l'API trovano nulla, $mapped resta vuoto: la scrittura azzera
        // comunque, esplicitamente — nessun valore precedente va preservato in questo path
        // (decisione esplicita del developer, oc:8487). La guardia contro un match locale
        // arrivato nel frattempo e la corretta serializzazione di un array vuoto sono
        // responsabilità del service (`writeTaxonomyWhereIfEmpty()`), condivise con l'eventuale
        // altro scrittore di questo campo.
        $service->writeTaxonomyWhereIfEmpty($this->model, $mapped);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncModelTaxonomyWhereJob failed after all retries', [
            'model' => get_class($this->model),
            'model_id' => $this->model->id,
            'error' => $e->getMessage(),
        ]);
    }
}
