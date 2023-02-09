<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Wm\WmPackage\Model\HoquCallerJob;

class CallerController extends Controller
{
    //use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    use  DispatchesJobs, ValidatesRequests;

    /**
     * Alias of done()
     */
    public function donedone(Request $request)
    {
        return $this->done($request);
    }

    /**
     * When caller receive output
     *
     * @param  Request  $request
     * @return void
     */
    public function done(Request $request)
    {
        $fields = $request->validate([
            'output' => 'required|string',
            'hoqu_job_id' => 'required|integer',
        ]);
        $hoquCallerJob = HoquCallerJob::where('job_id', $fields['hoqu_job_id'])->firstOrFail();
        $field = $hoquCallerJob->field_to_update;
        $feature = $hoquCallerJob->feature;

        $feature->$field = $fields['output'];

        $feature->save();

        return response('ok', 200);
    }
}
