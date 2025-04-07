<?php

namespace Wm\WmPackage\Http\Controllers\Api\Abstracts;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;

abstract class UgcController extends Controller
{
    abstract protected function getModelIstance(): GeometryModel;

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

        $model = $this->fillModelWithRequest($this->getModelIstance(), $request, $validated);

        return response()->json(['id' => $model->id, 'message' => 'Created successfully'], 201);
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
        $validated = $this->validate($request, ['properties.id' => 'required|exists:ugc_pois,id']);
        $model = $this->getModelIstance()->find($validated['properties']['id']);

        return $this->_update($request, $model);
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
            'app_id' => $properties['app_id'],
        ]);

        try {
            $model->save();
        } catch (\Exception $e) {
            $message = 'Error saving ' . class_basename($this->getModelIstance()::class) . '. ' . $e->getMessage();
            Log::channel('ugc')->error($message);
            throw new Exception($message, 500);
        }

        if ($request->has('images')) {
            $model->addMultipleMediaFromRequest(['images'])
                ->each(function ($fileAdder) {
                    $fileAdder->toMediaCollection('default');
                });
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
                $featureCollection['features'][] = $feature->getGeojson();
            }
        }

        return response()->json($featureCollection);
    }
}
