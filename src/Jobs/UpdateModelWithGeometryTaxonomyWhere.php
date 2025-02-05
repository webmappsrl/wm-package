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
        if (count($wheres) === 0) {
            Log::warning('No wheres found for '.class_basename($this->model).' '.$this->model->id);

            return;
        }

        $this->model->properties['taxonomy_where'] = $wheres;
        $this->model->saveQuietly();
    }
}
