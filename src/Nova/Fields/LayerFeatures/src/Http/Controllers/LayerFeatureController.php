<?php

namespace Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;

class LayerFeatureController
{
    private PBFGeneratorService $pbfGeneratorService;

    private LayerService $layerService;

    public function __construct(PBFGeneratorService $pbfGeneratorService, LayerService $layerService)
    {
        $this->pbfGeneratorService = $pbfGeneratorService;
        $this->layerService = $layerService;
    }

    public function index(Request $request, $layerId): JsonResponse
    {
        try {
            $layer = Layer::findOrFail($layerId);

            // Ottieni il modello dalla query string
            $modelClass = $request->query('model');

            if (! $modelClass) {
                return response()->json([
                    'error' => 'Parametro model mancante',
                ], 400);
            }

            // Verifica che il modello esista
            if (! class_exists($modelClass)) {
                return response()->json([
                    'error' => "Modello '{$modelClass}' non trovato",
                ], 400);
            }

            // Risolve il morph type tramite la morph map (es. Wm\...\EcTrack → App\Models\EcTrack)
            $morphType = array_search($modelClass, Relation::morphMap()) ?: $modelClass;

            $count = DB::table('layerables')
                ->where('layer_id', $layerId)
                ->where('layerable_type', $morphType)
                ->count();

            return response()->json([
                'layer_id' => $layerId,
                'layer_name' => $layer->name,
                'model_class' => $modelClass,
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            Log::error('LayerFeatureController::index error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Errore interno del server: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getFeatures(Request $request, $layerId): JsonResponse
    {
        try {
            $layer = Layer::findOrFail($layerId);

            $validatedData = $request->validate([
                'model' => 'required|string',
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:100',
                'search' => 'string|nullable',
                'view_mode' => 'string|in:details,edit',
            ]);

            $page = $validatedData['page'] ?? 1;
            $perPage = $validatedData['per_page'] ?? 50;
            $search = $validatedData['search'] ?? '';
            $viewMode = $validatedData['view_mode'] ?? 'edit';
            $isManualRequest = filter_var($request->query('manual', false), FILTER_VALIDATE_BOOLEAN);

            $model = new $validatedData['model'];

            if (! method_exists($model, 'getLayerRelationName')) {
                return response()->json([
                    'error' => "Il modello '{$validatedData['model']}' non implementa l'interfaccia LayerRelatedModel.",
                ], 400);
            }

            $relationName = $model->getLayerRelationName();
            $featureTable = $model->getTable();
            $taxonomyIds = $layer->taxonomyActivities->pluck('id')->toArray();
            $hasTaxonomyRelation = method_exists($model, 'taxonomyActivities');
            $isAuto = $layer->isAutoTrackMode();
            $useAutoMode = ! $isManualRequest && $isAuto;
            $associatedQuery = $layer->{$relationName}()
                ->select([$featureTable.'.id as id', $featureTable.'.name as name']);

            if (! empty($taxonomyIds) && $hasTaxonomyRelation) {
                $associatedQuery->whereHas('taxonomyActivities', fn ($q) => $q->whereIn('taxonomy_activities.id', $taxonomyIds));
            }
            $associatedTracks = $associatedQuery->orderBy($featureTable.'.name', 'ASC')->get();

            // Fallback temporaneo in lettura: solo in auto, solo con taxonomy activities, solo se pivot vuoto.
            if ($useAutoMode && ! empty($taxonomyIds) && $associatedTracks->isEmpty() && $relationName === 'ecTracks') {
                // Se il pivot è vuoto in auto, prova prima a riallinearlo.
                $this->layerService->assignTracksByTaxonomy($layer);

                $associatedQuery = $layer->{$relationName}()
                    ->select([$featureTable.'.id as id', $featureTable.'.name as name']);
                if ($hasTaxonomyRelation) {
                    $associatedQuery->whereHas('taxonomyActivities', fn ($q) => $q->whereIn('taxonomy_activities.id', $taxonomyIds));
                }
                $associatedTracks = $associatedQuery->orderBy($featureTable.'.name', 'ASC')->get();

                // Fallback UI: se resta vuoto, mostra comunque il risultato runtime.
                if ($associatedTracks->isEmpty()) {
                    $fallbackQuery = $model->newQuery();
                    $appIds = array_values(array_unique(array_filter([
                        $layer->app_id,
                        ...$layer->associatedApps->pluck('id')->toArray(),
                    ])));

                    if (! empty($appIds)) {
                        $fallbackQuery->whereIn('app_id', $appIds);
                    }

                    if ($hasTaxonomyRelation) {
                        $fallbackQuery->whereHas('taxonomyActivities', fn ($q) => $q->whereIn('taxonomy_activities.id', $taxonomyIds));
                    }

                    $associatedTracks = $fallbackQuery->select(['id', 'name'])->orderBy('name', 'ASC')->get();
                }
            }

            if ($useAutoMode || $viewMode === 'details') {
                // Auto o details: mostra le tracks effettivamente associate al layer
                $selectedTracks = $this->filterTracksBySearch($associatedTracks, $search);

                $total = $selectedTracks->count();
                $offset = ($page - 1) * $perPage;
                $paginatedItems = $selectedTracks
                    ->sortBy(fn ($track) => $this->normalizeFeatureName($track->name))
                    ->slice($offset, $perPage)
                    ->values();

                $features = new LengthAwarePaginator($paginatedItems, $total, $perPage, $page, ['path' => request()->url(), 'pageName' => 'page']);
            } else {
                // Modalità manuale edit: tracks selezionate (da layerables + filtro tassonomia) prima, poi le altre
                $selectedTracks = $this->filterTracksBySearch($associatedTracks, $search);

                // Altre tracks dell'app, filtrate per tassonomia se presenti
                $otherQuery = $model->newQuery();
                if ($layer->app_id) {
                    $otherQuery->where('app_id', $layer->app_id);
                }
                if (! empty($taxonomyIds) && $hasTaxonomyRelation) {
                    $otherQuery->whereHas('taxonomyActivities', fn ($q) => $q->whereIn('taxonomy_activities.id', $taxonomyIds));
                }
                if ($selectedTracks->isNotEmpty()) {
                    $otherQuery->whereNotIn('id', $selectedTracks->pluck('id'));
                }
                if ($search) {
                    $otherQuery->where('name', 'like', "%{$search}%");
                }

                $otherTracks = $otherQuery->select(['id', 'name'])->orderBy('name', 'ASC')->get();
                $allFeatures = $selectedTracks->concat($otherTracks);

                $total = $allFeatures->count();
                $offset = ($page - 1) * $perPage;
                $paginatedItems = $allFeatures->slice($offset, $perPage)->values();

                $features = new LengthAwarePaginator($paginatedItems, $total, $perPage, $page, ['path' => request()->url(), 'pageName' => 'page']);
            }

            return response()->json([
                'features' => array_values($features->items()),
                'pagination' => [
                    'current_page' => $features->currentPage(),
                    'last_page' => $features->lastPage(),
                    'per_page' => $features->perPage(),
                    'total' => $features->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('LayerFeatureController::getFeatures error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Errore interno del server: '.$e->getMessage(),
            ], 500);
        }
    }

    public function sync(Request $request, $layerId): JsonResponse
    {
        $layer = Layer::findOrFail($layerId);

        $validatedData = $request->validate([
            'features' => 'array',
            'model' => 'required|string',
            'auto' => 'boolean',
        ]);

        // Creo un'istanza del modello per ottenere il nome della relazione
        $model = new $validatedData['model'];

        if (! method_exists($model, 'getLayerRelationName')) {
            return response()->json([
                'error' => "Il modello '{$validatedData['model']}' non implementa l'interfaccia LayerRelatedModel.",
            ], 400);
        }

        $relationName = $model->getLayerRelationName();

        if (! method_exists($layer, $relationName)) {
            return response()->json([
                'error' => "La relazione '{$relationName}' non esiste nel modello Layer.",
            ], 400);
        }

        $isAutoRequest = ! empty($validatedData['auto']) && $relationName === 'ecTracks';
        if ($isAutoRequest) {
            // In modalità automatica il pivot ecTracks viene ricalcolato da taxonomy
            $this->layerService->assignTracksByTaxonomy($layer);
        } else {
            $layer->{$relationName}()->sync($validatedData['features'] ?? []);
        }

        $this->pbfGeneratorService->regeneratePbfsForLayer($layer);

        $tableName = $model->getTable();
        $assignedIds = $layer->{$relationName}()->select($tableName.'.id')->pluck('id')->toArray();

        return response()->json([
            'message' => 'Features sincronizzate con successo',
            'assigned_ids' => $assignedIds,
        ], 200);
    }

    private function filterTracksBySearch($tracks, string $search)
    {
        if ($search === '') {
            return $tracks;
        }

        $searchNeedle = strtolower($search);

        return $tracks->filter(fn ($track) => str_contains($this->normalizeFeatureName($track->name), $searchNeedle));
    }

    private function normalizeFeatureName(mixed $name): string
    {
        if (is_array($name)) {
            foreach ($name as $value) {
                if (is_string($value) && $value !== '') {
                    return strtolower($value);
                }
            }

            return '';
        }

        return strtolower((string) ($name ?? ''));
    }
}
