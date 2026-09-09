<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\TaxonomyWhere;

class CopyTaxonomyWhereGeometryFromGeohubJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $taxonomyWhereId,
        public int $geohubTaxonomyWhereId,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $taxonomyWhere = TaxonomyWhere::findOrFail($this->taxonomyWhereId);

        $row = DB::connection('geohub')->selectOne(
            'SELECT ST_AsGeoJSON(geometry) as geojson FROM taxonomy_wheres WHERE id = ?',
            [$this->geohubTaxonomyWhereId]
        );

        $geojson = $row->geojson ?? null;

        if (empty($geojson)) {
            Log::warning('TaxonomyWhere geometry not available from GeoHub', [
                'taxonomy_where_id' => $this->taxonomyWhereId,
                'geohub_taxonomy_where_id' => $this->geohubTaxonomyWhereId,
            ]);

            return;
        }

        DB::statement(
            'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
            [$geojson, $taxonomyWhere->id]
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::error('CopyTaxonomyWhereGeometryFromGeohubJob failed after all retries', [
            'taxonomy_where_id' => $this->taxonomyWhereId,
            'geohub_taxonomy_where_id' => $this->geohubTaxonomyWhereId,
            'error' => $e->getMessage(),
        ]);
    }
}
