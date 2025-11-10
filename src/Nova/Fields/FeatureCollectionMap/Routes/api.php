<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// Route per il GeoJSON endpoint del FeatureCollectionMap con formato: /{model}/{id}
Route::get('/{model}/{id}', function ($model, $id) {
    // Converti lo slug in nome della classe (es: hiking-route -> HikingRoute)
    $className = \Illuminate\Support\Str::studly($model);
    
    // Prova a trovare la classe
    $modelClass = "\\App\\Models\\{$className}";
    
    // Se non esiste in App\Models, prova in Wm\WmPackage\Models
    if (!class_exists($modelClass)) {
        $modelClass = "\\Wm\\WmPackage\\Models\\{$className}";
    }
    
    // Se non troviamo la classe, restituiamo 404
    if (!class_exists($modelClass)) {
        abort(404, "Model class for '{$model}' not found");
    }
    
    // Trova il record
    $record = $modelClass::findOrFail($id);
    
    // Verifica che il modello abbia il metodo getFeatureCollectionMap
    if (!method_exists($record, 'getFeatureCollectionMap')) {
        abort(500, "Model {$modelClass} does not have getFeatureCollectionMap method");
    }
    
    // Chiama il metodo per ottenere il GeoJSON
    try {
        $geojson = $record->getFeatureCollectionMap();
        return response()->json($geojson);
    } catch (\Exception $e) {
        Log::error('FeatureCollectionMap error', [
            'model' => $modelClass,
            'id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        abort(500, "Error generating GeoJSON: " . $e->getMessage());
    }
})->name('feature-collection-map.geojson');

// Route per il widget della mappa con formato: /widget/{model}/{id}
Route::get('/widget/{model}/{id}', function ($model, $id) {
    // Costruiamo l'URL del GeoJSON
    $geojsonUrl = url("/nova-vendor/feature-collection-map/{$model}/{$id}");
    
    return view('nova.fields.feature-collection-map::feature-collection-map', [
        'geojsonUrl' => $geojsonUrl,
        'model' => $model
    ]);
})->name('feature-collection-map.widget');
