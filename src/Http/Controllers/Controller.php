<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Validator;

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

    protected function checkValidation($data, $rules)
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $currentErrors = json_decode($validator->errors(), true);
            $errors = [];
            foreach ($currentErrors as $key => $error) {
                $errors[$key] = $error;
            }

            return response(['error' => $errors], 400);
        }
    }
}
