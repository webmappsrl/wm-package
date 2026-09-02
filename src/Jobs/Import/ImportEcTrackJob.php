<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTheme;

class ImportEcTrackJob extends BaseEcImportJob
{
    protected function getModelKey(): string
    {
        return parent::getModelKey().'track';
    }

    protected function getGeometryType(): string
    {
        return 'MULTILINESTRING Z';
    }

    protected function processDependencies(array $data, Model $model): void
    {
        $ecPoiIdsWithOrder = $this->geohubImportService->getAssociatedEcPoisIDs($this->getModelKey(), $data['id']);

        $syncData = [];
        foreach ($ecPoiIdsWithOrder as $poiId => $order) {
            $syncData[$poiId] = ['order' => $order];
        }

        $model->ecPois()->sync($syncData);

        if (! config('wm-geohub-import.child_side_taxonomy_sync.enabled', true)) {
            return;
        }

        $this->syncTaxonomyFromEmbeddedProperty($model, 'activities', 'taxonomyActivities', TaxonomyActivity::class, [
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        $this->syncTaxonomyFromEmbeddedProperty($model, 'themes', 'taxonomyThemes', TaxonomyTheme::class);
    }

    /**
     * Sincronizza un tipo di taxonomy leggendo il campo JSON già presente nel payload Geohub
     * del track stesso (properties[$propertyKey], formato {geohub_taxonomy_id: [identifier,...]})
     * — evita la race condition coi job taxonomy dedicati, che dipendono invece dall'ordine dei
     * batch (vedi oc:8094). Errori sono loggati e ingoiati: non devono far fallire l'import del
     * track, già creato con successo.
     */
    private function syncTaxonomyFromEmbeddedProperty(
        Model $model,
        string $propertyKey,
        string $relationName,
        string $taxonomyClass,
        array $pivotData = []
    ): void {
        $logger = $this->importLogger();

        try {
            $decoded = json_decode($model->properties[$propertyKey] ?? '{}', true);
            $entries = is_array($decoded) ? $decoded : [];

            if (empty($entries)) {
                return;
            }

            foreach ($entries as $identifiers) {
                if (! is_array($identifiers)) {
                    continue;
                }

                foreach ($identifiers as $identifier) {
                    $taxonomy = $this->findTaxonomyByIdentifier($taxonomyClass, $identifier);

                    if (! $taxonomy) {
                        $logger->warning("[child_side_sync] Taxonomy not found for identifier '{$identifier}' ({$taxonomyClass}), track ID: {$model->id}");

                        continue;
                    }

                    // Solo se non già presente: syncWithoutDetaching() con $pivotData non vuoto
                    // (caso activities) chiamerebbe updateExistingPivot() anche su un pivot già
                    // esistente, sovrascrivendo duration_forward/duration_backward reali
                    // (scritti dal job taxonomy dedicato) con gli 0/0 hardcoded qui, ad ogni
                    // re-import. Comportamento del codice originale pre-refactor (exists-check
                    // prima di attach()), perso durante la generalizzazione — vedi oc:8094 review.
                    $alreadyAttached = $model->{$relationName}()->where($taxonomy->getTable().'.'.$taxonomy->getKeyName(), $taxonomy->getKey())->exists();

                    if ($alreadyAttached) {
                        continue;
                    }

                    $model->{$relationName}()->attach($taxonomy->id, $pivotData);
                    $logger->info("[child_side_sync] Attached {$taxonomyClass} ID {$taxonomy->id} to EcTrack ID {$model->id}");
                }
            }
        } catch (\Throwable $e) {
            $logger->error("[child_side_sync] Failed to sync {$taxonomyClass} for EcTrack ID {$model->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Trova la taxonomy per identifier. Prova prima un match esatto: alcuni identifier reali
     * sono sottostringa di altri (es. 'via-francigena' di 'via-francigena-toscana-sud') — senza
     * questo tentativo, il fallback LIKE sotto (nessun ORDER BY, ->first() su match multipli)
     * potrebbe attaccare silenziosamente la taxonomy sbagliata quando l'identifier esatto esiste
     * già (verificato: 33 collisioni reali tra i 502 identifier taxonomy_themes su Geohub, oc:8094
     * review). Il match parziale case-insensitive resta come fallback per gli identifier che non
     * corrispondono esattamente (es. varianti di case non previste), con fallback finale su
     * properties->geohub_id se il valore non è un identifier testuale.
     */
    private function findTaxonomyByIdentifier(string $taxonomyClass, string $identifier): ?Taxonomy
    {
        $taxonomy = $taxonomyClass::where('identifier', $identifier)->first();

        if ($taxonomy) {
            return $taxonomy;
        }

        $taxonomy = $taxonomyClass::where('identifier', 'like', '%'.$identifier.'%')
            ->orWhere('identifier', 'like', '%'.ucfirst($identifier).'%')
            ->orWhere('identifier', 'like', '%'.strtoupper($identifier).'%')
            ->first();

        if (! $taxonomy) {
            $taxonomy = $taxonomyClass::where('properties->geohub_id', $identifier)->first();
        }

        return $taxonomy;
    }
}
