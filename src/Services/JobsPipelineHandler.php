<?php

namespace Wm\WmPackage\Services;

use Exception;
use Wm\WmPackage\Enums\JobStatus;
use Wm\WmPackage\Facades\HoquClient;
use Wm\WmPackage\Model\HoquCallerJob;

class JobsPipelineHandler
{
    /**
     * Send a STORE request to hoqu, then create a job with status progress on this instance
     *
     * @param  string  $class
     * @param  string  $input
     * @param  string  $field
     * @param  Model  $model
     * @return void
     *
     * @throws Exception
     */
    public function createCallerStoreJobsPipeline($class, $input, $field, $model)
    {
        $hoquCallerJob = HoquCallerJob::make([
            'class' => $class,
            'field_to_update' => $field,
            'status' => JobStatus::New,
        ]);

        /**
         * Send store authenticated request to hoqu
         */
        $response = HoquClient::store([
            'name' => $class,
            'input' => $input,
        ]);

        if ($response->ok()) {
            $hoquCallerJob->job_id = $response['job_id'];

            $hoquCallerJob->feature()->associate($model)->save();
        } else {
            //TODO: create specific exception
            throw new Exception("Something went wrong during hoqu http store request:\n".print_r($response->body(), true));
        }
    }
}
