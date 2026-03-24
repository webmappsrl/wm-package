<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Models\TaxonomyWhere;

class FetchTaxonomyWhereGeometryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public int $taxonomyWhereId) {}

    public function handle(OsmfeaturesClient $client): void
    {
        $taxonomyWhere = TaxonomyWhere::findOrFail($this->taxonomyWhereId);
        $detail = $client->getAdminAreaDetail($taxonomyWhere->osmfeatures_id);

        if (empty($detail['geometry'])) {
            Log::warning('TaxonomyWhere geometry not available from OSMFeatures', [
                'taxonomy_where_id' => $this->taxonomyWhereId,
                'osmfeatures_id'    => $taxonomyWhere->osmfeatures_id,
            ]);
            return;
        }

        $taxonomyWhere->geometry = $detail['geometry'];
        $taxonomyWhere->save();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FetchTaxonomyWhereGeometryJob failed after all retries', [
            'taxonomy_where_id' => $this->taxonomyWhereId,
            'error'             => $e->getMessage(),
        ]);
    }
}
