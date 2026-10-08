<?php

namespace Wm\WmPackage\Http\Controllers\Api\Abstracts;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\UgcMediaHashService;

abstract class UgcController extends Controller
{
    abstract protected function getModelIstance(?Request $request = null): GeometryModel;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = $this->getModelIstance()->where('user_id', $user->id);

        // TODO: is it regular on header?
        if (! empty($request->header('app-id'))) {
            $validated = $this->validateAppId(['app-id' => $request->header('app-id')], 'app-id');
            $query = $query->where('app_id', $validated['app-id']);
        }

        $tracks = $query->orderByRaw('updated_at DESC')->get();

        return $this->getFeatureCollection($tracks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateGeojson($request);

        $existing = $this->findExistingByUuid($validated);
        if ($existing) {
            // Retry dell'app con lo stesso uuid (oc:8718): si aggiorna il record. Le properties
            // ricevute vanno sopra quelle salvate, così restano le chiavi scritte dal server che
            // l'app non conosce (es. layer_id calcolato).
            $validated['properties'] = array_merge($existing->properties ?? [], $validated['properties']);
        }

        $model = $this->fillModelWithRequest($existing ?? $this->getModelIstance(), $request, $validated);

        $this->enrichUgcWithTaxonomyWhere($model);

        return response()->json(['id' => $model->id, 'message' => 'Created successfully'], 201);
    }

    /**
     * UGC già salvato con lo stesso properties.uuid. L'uuid si legge dai dati validati: l'app manda
     * un multipart con la feature in JSON nel campo `feature`, che `$request->input()` non decodifica.
     * Con duplicati già presenti si prende il più vecchio, cioè il padre del command di
     * normalizzazione (oc:8718).
     */
    protected function findExistingByUuid(array $validated): ?GeometryModel
    {
        $uuid = $validated['properties']['uuid'] ?? null;
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        // Solo tra gli UGC dell'utente: l'uuid è pubblico nel link di condivisione
        // (/share/ugc-track/{uuid}), e un retry legittimo arriva sempre dallo stesso utente.
        return $this->getModelIstance()->newQuery()
            ->where('properties->uuid', $uuid)
            ->where('user_id', auth()->id())
            ->orderBy('id')
            ->first();
    }

    /**
     * Show the form for editing the specified resource.
     */
    protected function _update(Request $request, GeometryModel $model): JsonResponse
    {
        $this->validateUser($model);
        $validated = $this->validateGeojson($request);

        $model = $this->fillModelWithRequest($model, $request, $validated);

        return response()->json(['id' => $model->id, 'message' => 'Updated successfully'], 200);
    }

    public function legacyUpdate(Request $request): JsonResponse
    {
        $validated = $this->validate($request, ['properties.id' => 'required|exists:'.$this->getModelIstance()->getTable().',id']);
        $model = $this->getModelIstance()->find($validated['properties']['id']);

        return $this->_update($request, $model);
    }

    public function updateV3(Request $request): JsonResponse
    {
        $validated = $this->validateGeojson($request, ['properties.id' => 'required|exists:'.$this->getModelIstance()->getTable().',id']);
        $model = $this->getModelIstance()->find($validated['properties']['id']);
        $this->validateUser($model);

        $model = $this->fillModelWithRequest($model, $request, $validated);

        $this->enrichUgcWithTaxonomyWhere($model);

        return response()->json(['id' => $model->id, 'message' => 'Updated successfully'], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    protected function _destroy(GeometryModel $model): JsonResponse
    {
        $this->validateUser($model);
        try {
            $model->delete();
        } catch (Exception $e) {
            return response()->json([
                'error' => "this model can't be deleted by api",
                'code' => 400,
            ], 400);
        }

        return response()->json(['success' => 'model deleted']);
    }

    protected function fillModelWithRequest($model, $request, $validated)
    {
        $geometry = $validated['geometry'];
        $properties = $validated['properties'];

        $model = $model->fill([
            // validated in the validateProperties method
            'geometry' => GeometryComputationService::make()->getGeometryFromGeojsonRAW(json_encode($geometry)),
            'properties' => $properties,
            'name' => $properties['name'], // validated in the validateProperties method
            'app_id' => $properties['app_id'] ?? $model->app_id, // for those UGCs created from Nova, the app_id is not present in the properties, so we use the one from the model
            'created_by' => 'device',
        ]);

        try {
            $model->save();
        } catch (Exception $e) {
            $message = 'Error saving '.class_basename($this->getModelIstance()::class).'. '.$e->getMessage();
            Log::channel('ugc')->error($message);
            throw new Exception($message, 500);
        }

        if ($request->hasFile('images')) {
            $hashes = UgcMediaHashService::make();
            foreach ((array) $request->file('images') as $file) {
                $hash = $hashes->hashOfFile($file->getRealPath());
                // Immagine già presente (retry dell'app): non si salva.
                if ($hashes->findByContent($model, $hash)) {
                    continue;
                }
                $model->addMedia($file)
                    ->withCustomProperties([UgcMediaHashService::HASH_PROPERTY => $hash])
                    ->toMediaCollection('default');
            }
        }

        return $model;
    }

    protected function getFeatureCollection($features): JsonResponse
    {
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];

        if ($features) {
            foreach ($features as $feature) {
                $geojson = $feature->getGeojson();

                // Skip features with null or invalid geojson (e.g., old UGCs without valid geometry)
                if ($geojson === null || ! is_array($geojson) || ! isset($geojson['properties'])) {
                    Log::warning("UGC feature ID {$feature->id} has null/invalid geojson, skipped", [
                        'geojson_type' => gettype($geojson),
                        'has_geometry' => ! empty($feature->geometry),
                    ]);

                    continue;
                }

                $geojson['properties'] = $feature->applyTaxonomyWhereDisplay($geojson['properties']);

                $geojson['properties']['media'] = $feature->getMedia()->map(fn ($media) => [
                    'id' => $media->id,
                    'name' => $media->name,
                    'webPath' => $media->getUrl(),
                ]);

                $featureCollection['features'][] = $geojson;
            }
        }

        return response()->json($featureCollection);
    }

    /**
     * Runs taxonomy_where sync (osmfeatures) synchronously.
     * On failure logs a warning, dispatches the same job asynchronously as fallback,
     * and does not alter the successful HTTP response.
     */
    protected function enrichUgcWithTaxonomyWhere(GeometryModel $model): void
    {
        try {
            UpdateModelWithGeometryTaxonomyWhere::dispatchSync($model);
        } catch (Throwable $e) {
            Log::channel('ugc')->warning('UpdateModelWithGeometryTaxonomyWhere failed: '.$e->getMessage(), [
                'model' => $model::class,
                'id' => $model->id,
            ]);
            UpdateModelWithGeometryTaxonomyWhere::dispatch($model);
        }
    }
}
