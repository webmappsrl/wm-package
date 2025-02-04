<?php

namespace Wm\WmPackage\Http\Controllers\Api\Abstracts;

use Exception;
use Illuminate\Http\Request;
use Wm\WmPackage\Models\App;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Traits\FeatureCollectionTrait;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;

abstract class UgcController extends Controller
{

    abstract protected function getModelIstance(): GeometryModel;

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $user = auth('api')->user();

        $query = $this->getModelIstance()->getQuery()->where('user_id', $user->id);

        //TODO: is it regular on header?
        if (! empty($request->header('app-id'))) {
            $validated = $this->validateAppId($request->headers(), 'app-id');
            $query = $query->where('app_id', $validated['app-id']);
        }

        $tracks = $query->orderByRaw('updated_at DESC')->get();

        return $this->getFeatureCollection($tracks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): Response
    {
        $validated = $this->validateGeojson($request);

        $model = $this->fillModelWithRequest($this->getModelIstance(), $request, $validated);

        return response(['id' => $model->id, 'message' => 'Created successfully'], 201);
    }


    /**
     * Show the form for editing the specified resource.
     */
    protected function _update(Request $request, GeometryModel $model): Response
    {
        $this->validateUser($model);
        $validated = $this->validateGeojson($request);

        $model = $this->fillModelWithRequest($model, $request, $validated);

        return response(['id' => $model->id, 'message' => 'Updated successfully'], 200);
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param  \Wm\WmPackage\Models\Abstracts\GeometryModel $model
     * @return Response
     */
    protected function _destroy(GeometryModel $model)
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

        $model = ($this->getModelIstance())->fill([
            //validated in the validateProperties method
            'geometry' => GeometryComputationService::make()->get2dGeometryFromGeojsonRAW(json_encode($geometry)),
            'properties' => $properties,
            'name' => $properties['name'], //validated in the validateProperties method
            'app_id' =>  $properties['app_id'] //validated in the validateProperties method
        ]);

        try {
            $model->save();
        } catch (\Exception $e) {
            Log::channel('ugc')->info('Errore nel salvataggio dell\'oggetto ' . $this->getModelIstance()::class . ':' . $e->getMessage());
            return response(['error' => 'Error saving ' . class_basename($this->getModelIstance()::class)], 500);
        }

        if ($request->has('images')) {
            $model->addMultipleMediaFromRequest(['images'])
                ->each(function ($fileAdder) {
                    $fileAdder->toMediaCollection('default');
                });
        }

        return $model;
    }

    protected function getFeatureCollection($features)
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
