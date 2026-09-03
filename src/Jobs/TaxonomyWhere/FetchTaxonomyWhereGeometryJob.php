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

class FetchTaxonomyWhereGeometryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

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
     * L'endpoint list di OSMFeatures espone solo le traduzioni name:<lang>, non
     * il tag 'name' base. Quando mancano 'it' e 'en' l'import salva l'id come
     * segnaposto: qui lo si sostituisce col nome reale, gia' presente nel
     * dettaglio che questo job scarica comunque per la geometria.
     */
    protected function syncNameFromDetail(TaxonomyWhere $taxonomyWhere, array $detail, string $osmfeaturesId): void
    {
        $baseName = $detail['name'] ?? null;

        if (empty($baseName) || $baseName === $osmfeaturesId) {
            return;
        }

        $translations = $taxonomyWhere->getTranslations('name');
        $hasReliableName = ! empty($translations['it']) || ! empty($translations['en']);
        $isPlaceholder = in_array($osmfeaturesId, $translations, true);

        if ($hasReliableName && ! $isPlaceholder) {
            return;
        }

        $taxonomyWhere->setTranslation('name', 'it', $baseName);
        $taxonomyWhere->save();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FetchTaxonomyWhereGeometryJob failed after all retries', [
            'taxonomy_where_id' => $this->taxonomyWhereId,
            'error' => $e->getMessage(),
        ]);
    }
}
