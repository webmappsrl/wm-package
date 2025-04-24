<?php

namespace Wm\WmPackage\Listeners;

use Illuminate\Queue\Events\JobFailed as QueueJobFailed;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\JobFailed as HorizonJobFailed;

class FailedJobsListener
{
    public function handle(QueueJobFailed|HorizonJobFailed $event)
    {
        $job = $event->job;
        $payload = json_decode($job->getRawBody(), true);
        $attempts = $job->attempts();
        $exception = $event->exception;

        Log::channel('wm-package-failed-jobs')->error("Failed Job: {$event->job->resolveName()}", [
            'job_id' => $job->getJobId(),
            'queue' => $job->getQueue(),
            'attempts' => $attempts,
            'exception' => $exception,
            'payload' => $payload,
            'failed_at' => now()->toDateTimeString(),
            'stacktrace' => $exception->getTraceAsString(),
        ]);
    }
}
