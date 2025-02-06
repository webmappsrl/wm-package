<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\Models\Abstracts\GeometryModel;

/**
 * @OA\Info(
 *      version="1.0.0",
 *      x={
 *          "logo": {
 *              "url": "http://localhost:8000/images/webmapp-logo-colored.png"
 *          }
 *      },
 *      title="GEOHUB",
 *      description="Api documentation",
 *
 *      @OA\Contact(
 *          email="info@webmapp.it"
 *      ),
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function validateAppId($data, $key = 'appId')
    {
        // https://laravel.com/docs/11.x/validation#stopping-on-first-validation-failure
        // https://laravel.com/docs/11.x/validation#rule-exists

        return Validator::make($data, [
            $key => 'integer|required|exists:apps,id',
        ])->validate();
    }

    protected function validateGeojson(Request $request, $additionalRules = [])
    {
        return $request->validate($request->get('properties'), [
            'type' => 'required',
            'properties' => 'required|array',
            'properties.name' => 'required|max:255',
            'properties.app_id' => 'integer|required|exists:apps,id',
            'geometry' => 'required|array',
            'geometry.type' => 'required',
            'geometry.coordinates' => 'required|array',
            ...$additionalRules,
        ]);
    }

    protected function validateUser(GeometryModel $model)
    {
        // TODO: skip this when there will be better model policies
        $user = auth('api')->user();
        if ($model->user_id !== $user->id) {
            return response(['error' => 'Forbidden access to another user model'], 403);
        }
    }
}
