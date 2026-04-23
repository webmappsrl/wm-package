<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\Osm2caiClient;
use Wm\WmPackage\Models\TaxonomyWhere;

class FetchOsm2caiSectorGeometryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $taxonomyWhereId) {}

    public function handle(Osm2caiClient $client): void
    {
        $taxonomyWhere = TaxonomyWhere::findOrFail($this->taxonomyWhereId);
        $osm2caiId = $taxonomyWhere->properties['osm2cai_id'] ?? null;

        if (empty($osm2caiId)) {
            Log::warning('TaxonomyWhere non ha osm2cai_id, skip geometry fetch', [
                'taxonomy_where_id' => $this->taxonomyWhereId,
            ]);

            return;
        }

        try {
            $detail = $client->getSectorDetail((int) $osm2caiId);
        } catch (Exception $e) {
            Log::warning('FetchOsm2caiSectorGeometryJob: detail non disponibile', [
                'taxonomy_where_id' => $this->taxonomyWhereId,
                'osm2cai_id' => $osm2caiId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (empty($detail['geometry'])) {
            Log::warning('FetchOsm2caiSectorGeometryJob: geometry vuota', [
                'taxonomy_where_id' => $this->taxonomyWhereId,
                'osm2cai_id' => $osm2caiId,
            ]);

            return;
        }

        // Merge extra properties from detail into stored properties
        $extraProperties = array_filter([
            'code' => $detail['code'],
            'full_code' => $detail['full_code'],
            'human_name' => $detail['human_name'],
            'manager' => $detail['manager'],
        ]);

        if (! empty($extraProperties)) {
            $taxonomyWhere->update([
                'properties' => array_merge($taxonomyWhere->properties ?? [], $extraProperties),
            ]);
        }

        DB::statement(
            'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
            [$detail['geometry'], $taxonomyWhere->id]
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FetchOsm2caiSectorGeometryJob failed after all retries', [
            'taxonomy_where_id' => $this->taxonomyWhereId,
            'error' => $e->getMessage(),
        ]);
    }
}
