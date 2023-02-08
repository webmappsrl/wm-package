<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProcessorController extends Controller
{
    //use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    use  DispatchesJobs, ValidatesRequests;

    public function do(Request $request)
    {
        $fields = $request->validate([
            'input' => 'required|string',
            //https://laravel.com/docs/9.x/validation#using-closures
            'name' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    //eg: AddAreaToJob
                    $classNamespace = $this->getJobClassNamespace($value);
                    if (!class_exists($classNamespace)) {
                        $fail('The ' . $attribute . ' is invalid. Impossible found the class with namespace ' . $classNamespace);
                    }
                },
            ],
        ]);

        //could response error to hoqu
        //TODO: send job id?
        $this->getJobClassNamespace($fields['name'])::dispatch($fields);

        return response('ok', 200);
    }

    public function getJobClassNamespace($name)
    {
        return "\App\Jobs\\" . $name;
    }
}
