<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

class UpdateModelWithGeometryTaxonomyWhere implements ShouldQueue
{
    use Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(protected GeometryModel $model) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(OsmfeaturesClient $osmfeaturesClient)
    {
        $wheres = $osmfeaturesClient->getWheresByGeojson($this->model->getGeojson());

        // Il controllo "nessun risultato" va fatto sul mappato, non su $wheres grezzo: se
        // osmfeatures restituisce solo voci senza nome, fromOsmfeatures() (che scarta le voci
        // senza nome, stesso comportamento di TaxonomyWhereDisplayService::normalize()) torna
        // un array vuoto — scrivere comunque `taxonomy_where: []` a questo punto azzererebbe un
        // valore esistente che writeTaxonomyWhereIfEmpty() poi non può più riempire, perché
        // confronta con `'{}'` (oc:8588).
        $mapped = app(TaxonomyWhereDisplayService::class)->fromOsmfeatures($wheres);
        if ($mapped === []) {
            Log::warning('No named wheres found for '.class_basename($this->model).' '.$this->model->id);

            return;
        }

        $properties = $this->model->properties;
        $properties['taxonomy_where'] = $mapped;
        $this->model->properties = $properties;
        $this->model->saveQuietly();
    }
}
