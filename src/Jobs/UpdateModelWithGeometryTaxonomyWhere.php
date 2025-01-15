<?php

namespace Wm\WmPackage\Jobs;

use Wm\WmPackage\Models\TaxonomyWhere;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;

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
    public function __construct(protected GeometryModel $model)
    {
        // TODO: add validation about where taxonomy relation existence

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(GeometryComputationService $geometryComputationService)
    {

        $ids = $geometryComputationService->getModelIntersections($this->model, TaxonomyWhere::class)->pluck('id')->toArray();

        if (! empty($ids)) {
            $this->model->taxonomyWheres()->sync($ids);
        }
    }
}
