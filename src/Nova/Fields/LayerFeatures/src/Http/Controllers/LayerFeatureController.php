<?php

namespace Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Layer;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\PBFGeneratorService;

class LayerFeatureController
{
    private PBFGeneratorService $pbfGeneratorService;

    public function __construct(PBFGeneratorService $pbfGeneratorService)
    {
        $this->pbfGeneratorService = $pbfGeneratorService;
    }

    public function index(Request $request, $layerId): JsonResponse
    {
        try {
            $layer = Layer::findOrFail($layerId);

            // Ottieni il modello dalla query string
            $modelClass = $request->query('model');

            if (!$modelClass) {
                return response()->json([
                    'error' => 'Parametro model mancante'
                ], 400);
            }

            // Verifica che il modello esista
            if (!class_exists($modelClass)) {
                return response()->json([
                    'error' => "Modello '{$modelClass}' non trovato"
                ], 400);
            }

            // Query ottimizzata per ottenere il count per il modello specifico
            $count = DB::table('layerables')
                ->where('layer_id', $layerId)
                ->where('layerable_type', $modelClass)
                ->count();

            return response()->json([
                'layer_id' => $layerId,
                'layer_name' => $layer->name,
                'model_class' => $modelClass,
                'count' => $count
            ]);
        } catch (\Exception $e) {
            Log::error('LayerFeatureController::index error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Errore interno del server: ' . $e->getMessage()
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

            // Creo un'istanza del modello per ottenere il nome della relazione
            $model = new $validatedData['model'];

            if (! method_exists($model, 'getLayerRelationName')) {
                return response()->json([
                    'error' => "Il modello '{$validatedData['model']}' non implementa l'interfaccia LayerRelatedModel.",
                ], 400);
            }

            // Funzione helper per caricare le features associate
            $getAssociatedFeatures = function () use ($model, $layerId, $search) {
                $query = $model->newQuery();
                $query->whereHas('associatedLayers', function ($q) use ($layerId) {
                    $q->where('layer_id', $layerId);
                });

                if ($search) {
                    $query->where('name', 'like', "%{$search}%");
                }

                return $query->select(['id', 'name'])->orderBy('name', 'ASC');
            };

            // Query ottimizzata per ottenere le features
            if ($viewMode === 'details') {
                // In modalità details, mostra solo le features associate al layer
                $features = $getAssociatedFeatures()->paginate($perPage, ['*'], 'page', $page);
            } else {
                // In modalità edit, fai due chiamate separate

                // 1. Carica le features associate al layer
                $associatedFeatures = $getAssociatedFeatures()->get();

                // 2. Carica le altre features dell'app (non associate)
                $otherQuery = $model->newQuery();
                if ($layer->app_id) {
                    $otherQuery->where('app_id', $layer->app_id);
                }

                // Escludi quelle già associate
                if ($associatedFeatures->isNotEmpty()) {
                    $otherQuery->whereNotIn('id', $associatedFeatures->pluck('id'));
                }

                if ($search) {
                    $otherQuery->where('name', 'like', "%{$search}%");
                }

                $otherFeatures = $otherQuery->select(['id', 'name'])
                    ->orderBy('name', 'ASC')
                    ->get();

                // 3. Concatenazione: prima le associate, poi le altre
                $allFeatures = $associatedFeatures->concat($otherFeatures);

                // 4. Paginazione manuale
                $total = $allFeatures->count();
                $offset = ($page - 1) * $perPage;
                $paginatedFeatures = $allFeatures->slice($offset, $perPage);

                // Crea un oggetto paginazione manuale
                $features = new \Illuminate\Pagination\LengthAwarePaginator(
                    $paginatedFeatures,
                    $total,
                    $perPage,
                    $page,
                    ['path' => request()->url(), 'pageName' => 'page']
                );
            }

            return response()->json([
                'features' => array_values($features->items()), // Converte in array e reindirizza
                'pagination' => [
                    'current_page' => $features->currentPage(),
                    'last_page' => $features->lastPage(),
                    'per_page' => $features->perPage(),
                    'total' => $features->total(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('LayerFeatureController::getFeatures error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Errore interno del server: ' . $e->getMessage()
            ], 500);
        }
    }

    public function sync(Request $request, $layerId): JsonResponse
    {
        $layer = Layer::findOrFail($layerId);

        $validatedData = $request->validate([
            'features' => 'array',
            'model' => 'required|string',
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

        $layer->{$relationName}()->sync($validatedData['features']);
        $this->pbfGeneratorService->regeneratePbfsForLayer($layer);

        return response()->json([
            'message' => 'Features sincronizzate con successo',
        ], 200);
    }
}
