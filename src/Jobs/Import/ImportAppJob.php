<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Jobs\UpdateAppConfigHomeLayerIdsJob;

class ImportAppJob extends BaseImportJob
{
    protected function getModelKey(): string
    {
        return 'app';
    }

    protected function transformData(array $data): array
    {

        // make a diff between data keys and apps columns in database
        $diff = array_diff(array_keys($data), Schema::getColumnListing('apps'));
        $transformedData = array_diff_key($data, array_flip($diff));

        // add geohub_id and geohub_synced_at to the transformed data
        $transformedData['properties']['geohub_id'] = $data['id'];
        $transformedData['properties']['geohub_synced_at'] = now();

        // we need to check if the user related exists in db. If not, we need to create it.
        $user = $this->geohubImportService->checkUserExistence($transformedData['user_id']);
        $transformedData['user_id'] = $user->id;
        unset($transformedData['id']);

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void
    {
        // Get the list of allowed dependencies from configuration or job data
        $allowedDependencies = $this->getAllowedDependencies();

        // foreach ($this->getRelations() as $modelKey => $relationData) {
        //     $this->queueEntityImport($modelKey, $userId, $relationData['foreign_key']);
        // }

        // Import only allowed dependencies
        if (in_array('taxonomy_activity', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_activity', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('taxonomy_poi_types', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_poi_types', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('taxonomy_theme', $allowedDependencies)) {
            $this->queueEntityImport('taxonomy_theme', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('ec_poi', $allowedDependencies)) {
            $this->queueEntityImport('ec_poi', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('ec_track', $allowedDependencies)) {
            $this->queueEntityImport('ec_track', $data['user_id'], 'user_id', $model->id);
        }

        if (in_array('layer', $allowedDependencies)) {
            $this->queueEntityImport('layer', $data['user_id'], 'app_id', $model->id);
        }

        if (in_array('ec_media', $allowedDependencies)) {
            $this->queueEntityImport('ec_media', $data['user_id'], 'user_id', $model->id);
        }

        // ugc_poi/ugc_track dispatched before ugc_media: the media job resolves the local
        // model to attach the photo to, so it needs poi/track to exist first (dispatch order
        // is not a hard guarantee under Horizon — see ImportUgcMediaJob's retry).
        if (in_array('ugc_poi', $allowedDependencies)) {
            $this->queueEntityImport('ugc_poi', $data['user_id'], 'app_id', $model->id);
        }

        if (in_array('ugc_track', $allowedDependencies)) {
            $this->queueEntityImport('ugc_track', $data['user_id'], 'app_id', $model->id);
        }

        if (in_array('ugc_media', $allowedDependencies)) {
            $this->queueEntityImport('ugc_media', $data['user_id'], 'app_id', $model->id);
        }
    }

    /**
     * Get the list of allowed dependencies
     */
    protected function getAllowedDependencies(): array
    {
        // All available dependencies (ugc_poi/ugc_track/ugc_media are opt-in only: listed here
        // so they are recognized when passed explicitly, but never in default_dependencies.app)
        $allDependencies = ['taxonomy_activity', 'taxonomy_poi_types', 'taxonomy_theme', 'ec_poi', 'ec_track', 'layer', 'ec_media', 'ugc_poi', 'ugc_track', 'ugc_media'];

        // First check if allowed_dependencies is passed in job data
        if (isset($this->data['allowed_dependencies']) && is_array($this->data['allowed_dependencies'])) {
            return $this->data['allowed_dependencies'];
        }

        // Fallback to configuration
        $configDependencies = config('wm-geohub-import.default_dependencies.app', $allDependencies);

        return is_array($configDependencies) ? $configDependencies : $allDependencies;
    }

    /**
     * Queue imports for entities associated with this app.
     *
     * NOTA: ciascuna dipendenza viene dispatchata come Bus::batch() indipendente (vedi sotto),
     * senza alcun chaining .then() tra batch — nessuna garanzia d'ordine di completamento tra,
     * ad esempio, il batch ec_poi/ec_track e il batch taxonomy. Vedi oc:8094: i job EcTrack/EcPoi
     * sincronizzano le proprie taxonomy pivot autonomamente (child-side sync) proprio per non
     * dipendere da questo ordine.
     */
    protected function queueEntityImport(string $entityModelKey, ?int $userId, string $entityForeignKey, int $appId): void
    {
        $logger = Log::channel('wm-package-failed-jobs');

        try {
            $whereCondition = null;
            $data = [];

            switch ($entityModelKey) {
                case 'layer':
                case 'ugc_poi':
                case 'ugc_track':
                case 'ugc_media':
                    // Filtered by the Geohub app itself (numeric app_id, not the owner's user_id):
                    // UGC content is authored by many different end users, not just the app owner.
                    $whereCondition = [$entityForeignKey => $this->entityId];
                    $data = ['app_id' => $appId];
                    break;
                case 'ec_media':
                    // Per ec_media, importiamo i media associati ai track dell'app, non solo quelli dell'utente
                    $whereCondition = null; // Gestiremo i media tramite relazioni
                    $data = ['app_id' => $appId, 'app_user_id' => $userId];
                    break;
                case strpos($entityModelKey, 'taxonomy') !== false: // import only taxonomies actually used by this app (oc:8094)
                    $whereCondition = ['id' => $this->geohubImportService->getUsedTaxonomyGeohubIdsForApp($entityModelKey, $this->entityId, $userId)];
                    break;
                default:
                    $whereCondition = [$entityForeignKey => $userId];
                    $data = ['app_id' => $appId];
                    break;
            }
            $ids = $this->geohubImportService->getGeohubIdsToImport($entityModelKey, $whereCondition, $data);

            if (count($ids) > 0) {
                $jobs = [];
                foreach ($ids as $id) {
                    $jobs[] = $this->geohubImportService->createJob($entityModelKey, $id, $data);
                }
                // create a batch and add the jobs to it
                $batch = Bus::batch($jobs)->name("app-dependencies-{$entityModelKey}-import-batch")->onQueue(config('wm-geohub-import.queue.queue', 'geohub-import'));
                $batch->dispatch();

                // Dopo aver dispatchato tutti i job dei layer, lancia l'aggiornamento di config_home
                if ($entityModelKey === 'layer') {
                    dispatch((new UpdateAppConfigHomeLayerIdsJob($appId))
                        ->onQueue(config('wm-geohub-import.queue.queue', 'geohub-import')));
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error queuing {$entityModelKey} imports for app {$this->entityId}: ".$e->getMessage());
            throw $e;
        }
    }
}
