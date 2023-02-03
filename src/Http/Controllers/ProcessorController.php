<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Wm\WmPackage\Jobs\ComputeJob;
use Wm\WmPackage\Model\HoquProcessorJob;
use Wm\WmPackage\Model\User;

class ProcessorController extends Controller
{
    //use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    use  DispatchesJobs, ValidatesRequests;

    public function processorDo(Request $request)
    {
        $fields = $request->validate([
            'input' => 'required|string',
            'name' => 'required|string',
        ]);

        ComputeJob::dispatch($fields);

        return response("ok", 200);
    }
}
