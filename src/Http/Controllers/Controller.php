<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\Interfaces\UserOwnedModelInterface;

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
        // Get data based on request type
        $data = $request->all();

        // If it's a store request, decode the feature field
        if (str_contains($request->route()->getName(), 'store')) {
            $data = json_decode($data['feature'], true);
        }

        // Set up validation rules
        $rules = [
            'type' => 'required|string',
            'properties' => 'required|array',
            'properties.name' => 'required|string|max:255',
            'geometry' => 'required|array',
            'geometry.type' => 'required|string',
            'geometry.coordinates' => 'required|array',
            ...$additionalRules,
        ];

        // Add properties.app_id validation only if not an update request. This is to avoid validation error when updating from the app those UGCs that were created from Nova.
        if (! str_contains($request->route()->getName(), 'update')) {
            $rules['properties.app_id'] = 'required|exists:apps,id';
        }

        // Create validator instance
        $validator = Validator::make($data, $rules);

        // Return all validation errors when it fails
        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            $errorMessage = '';
            foreach ($errors as $field => $messages) {
                $errorMessage .= "$field: [".implode(', ', $messages)."]\n";
            }
            abort(400, trim($errorMessage));
        }

        return $data;
    }

    protected function validateUser(UserOwnedModelInterface $model)
    {
        // TODO: skip this when there will be better model policies
        $user = auth()->user();
        if ($model->user_id !== $user->id) {
            return response(['error' => 'Forbidden access to another user model'], 403);
        }
    }
}
