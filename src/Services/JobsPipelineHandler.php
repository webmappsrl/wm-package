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
     * @param [type] $class
     * @param [type] $input
     * @param [type] $featureId
     * @param [type] $field
     * @return void
     *
     * @throws Exception
     */
    public function createCallerStoreJobsPipeline($class, $input, $featureId, $field)
    {
        /**
         * Send store authenticated request to hoqu
         */
        $response = HoquClient::store([
            'name' => $class,
            'input' => $input,
        ]);

        if ($response->isOk()) {
            HoquCallerJob::create([
                'job_id' => $response['job_id'],
                'class' => $class,
                'feature_id' => $featureId,
                'field_to_update' => $field,
                'status' => JobStatus::New,
            ]);
        } else {
            //TODO: create specific exception
            throw new Exception("Something went wrong during hoqu http store request:\n" . print_r($response, true));
        }
    }
}
