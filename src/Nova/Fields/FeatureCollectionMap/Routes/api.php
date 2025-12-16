<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Clients\DemClient;

// Route per il GeoJSON endpoint del FeatureCollectionMap con formato: /{model}/{id}
Route::get('/{model}/{id}', function ($model, $id) {
    // Converti lo slug in nome della classe (es: hiking-route -> HikingRoute)
    $className = \Illuminate\Support\Str::studly($model);

    // Prova a trovare la classe provando tutte le varianti:
    // 1. Nome esatto (es: HikingRoutes, Poles, TrailSurveys)
    // 2. Senza 's' finale (es: HikingRoute, Pole, TrailSurvey) - solo se finisce con 's'
    // 3. Con 's' finale (es: HikingRoutes) - solo se non finisce con 's'
    $modelClass = null;
    $candidates = [
        "\\App\\Models\\{$className}",
    ];

    // Aggiungi variante senza 's' se finisce con 's'
    // Gestisce casi come: trail-surveys -> TrailSurveys -> TrailSurvey
    if (str_ends_with($className, 's')) {
        $candidates[] = '\\App\\Models\\' . substr($className, 0, -1);
    }

    // Aggiungi variante con 's' se non finisce con 's'
    if (! str_ends_with($className, 's')) {
        $candidates[] = "\\App\\Models\\{$className}s";
    }

    // Stesse varianti per Wm\WmPackage\Models
    $wmCandidates = [];
    foreach ($candidates as $candidate) {
        $wmCandidates[] = str_replace('\\App\\Models\\', '\\Wm\\WmPackage\\Models\\', $candidate);
    }
    $candidates = array_merge($candidates, $wmCandidates);

    // Cerca tra i candidati
    foreach ($candidates as $candidate) {
        if (class_exists($candidate)) {
            $modelClass = $candidate;
            break;
        }
    }

    // Se non troviamo la classe, proviamo un fallback più intelligente
    // Cerca tra tutti i resource registrati in Nova per trovare quello che corrisponde
    if (! $modelClass) {
        try {
            $allResources = \Laravel\Nova\Nova::availableResources(request());
            foreach ($allResources as $resourceClass) {
                if (method_exists($resourceClass, 'uriKey')) {
                    $uriKey = $resourceClass::uriKey();
                    // Confronta il uriKey con il model slug
                    if ($uriKey === $model || str_replace('_', '-', $uriKey) === $model) {
                        if (property_exists($resourceClass, 'model') && $resourceClass::$model) {
                            $modelClass = $resourceClass::$model;
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignora errori nel fallback
            Log::debug('FeatureCollectionMap fallback error', ['error' => $e->getMessage()]);
        }
    }

    // Se non troviamo la classe, restituiamo 404 con informazioni di debug
    if (! $modelClass) {
        Log::warning('FeatureCollectionMap: Model class not found', [
            'model_slug' => $model,
            'className' => $className,
            'candidates_tried' => array_slice($candidates, 0, 6), // Limita a 6 per non loggare troppo
        ]);
        abort(404, "Model class for '{$model}' not found");
    }

    // Trova il record
    $record = $modelClass::findOrFail($id);

    // Verifica che il modello abbia il metodo getFeatureCollectionMap
    if (! method_exists($record, 'getFeatureCollectionMap')) {
        abort(500, "Model {$modelClass} does not have getFeatureCollectionMap method");
    }

    // Chiama il metodo per ottenere il GeoJSON
    try {
        $geojson = $record->getFeatureCollectionMap();

        // Se richiesto l'arricchimento DEM, chiama l'endpoint point-matrix
        if (request()->boolean('dem_enrichment')) {
            try {
                $demClient = new DemClient;
                $geojson = $demClient->getPointMatrix($geojson);
            } catch (\Exception $e) {
                Log::warning('DEM enrichment failed, returning original geojson', [
                    'model' => $modelClass,
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
                // In caso di errore, restituisci il GeoJSON originale senza arricchimento
            }
        }

        return response()->json($geojson);
    } catch (\Exception $e) {
        Log::error('FeatureCollectionMap error', [
            'model' => $modelClass,
            'id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        abort(500, 'Error generating GeoJSON: ' . $e->getMessage());
    }
})->name('feature-collection-map.geojson');

// Route per il widget della mappa con formato: /widget/{model}/{id}
Route::get('/widget/{model}/{id}', function ($model, $id) {
    // Costruiamo l'URL del GeoJSON
    $geojsonUrl = url("/nova-vendor/feature-collection-map/{$model}/{$id}");

    // Passa il parametro dem_enrichment se presente
    if (request()->boolean('dem_enrichment')) {
        $geojsonUrl .= '?dem_enrichment=1';
    }

    return view('nova.fields.feature-collection-map::feature-collection-map', [
        'geojsonUrl' => $geojsonUrl,
        'model' => $model,
    ]);
})->name('feature-collection-map.widget');

// Route per salvare lo screenshot della mappa
Route::post('/screenshot/{model}/{id}', function ($model, $id) {
    // Converti lo slug in nome della classe (es: hiking-route -> HikingRoute)
    $className = \Illuminate\Support\Str::studly($model);

    // Prova a trovare la classe provando tutte le varianti
    $modelClass = null;
    $candidates = [
        "\\App\\Models\\{$className}",
    ];

    if (str_ends_with($className, 's')) {
        $candidates[] = '\\App\\Models\\' . substr($className, 0, -1);
    }

    if (! str_ends_with($className, 's')) {
        $candidates[] = "\\App\\Models\\{$className}s";
    }

    // Cerca tra i candidati
    foreach ($candidates as $candidate) {
        if (class_exists($candidate)) {
            $modelClass = $candidate;
            break;
        }
    }

    if (! $modelClass) {
        abort(404, "Model class for '{$model}' not found");
    }

    // Trova il record
    $record = $modelClass::findOrFail($id);

    // Valida la richiesta
    request()->validate([
        'image' => 'required|string', // Base64 image data
    ]);

    try {
        // Decodifica l'immagine base64
        $imageData = request()->input('image');

        // Rimuovi il prefisso data:image/png;base64, se presente
        if (strpos($imageData, ',') !== false) {
            $imageData = explode(',', $imageData)[1];
        }

        $imageBinary = base64_decode($imageData);

        if ($imageBinary === false) {
            abort(400, 'Invalid base64 image data');
        }

        // Determina il percorso di salvataggio
        // Per TrailSurvey: trail-surveys/{id}/map_screenshot.png
        $directory = "trail-surveys/{$id}";
        $filename = 'map_screenshot.png';
        $path = "{$directory}/{$filename}";

        // Salva il file usando Storage
        $storage = \Illuminate\Support\Facades\Storage::disk('public');

        // Crea la directory se non esiste
        if (! $storage->exists($directory)) {
            $storage->makeDirectory($directory);
        }

        $storage->put($path, $imageBinary);

        // Restituisci il percorso relativo
        return response()->json([
            'success' => true,
            'path' => $path,
            'url' => $storage->url($path),
        ]);
    } catch (\Exception $e) {
        Log::error('Error saving map screenshot', [
            'model' => $modelClass,
            'id' => $id,
            'error' => $e->getMessage(),
        ]);
        abort(500, 'Error saving screenshot: ' . $e->getMessage());
    }
})->name('feature-collection-map.screenshot');
