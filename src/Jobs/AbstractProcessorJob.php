<?php

namespace Wm\WmPackage\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Wm\WmPackage\Http\HoquClient;

/**
 * Abstract class
 *
 * The abstract laravel job that validate input and start the processor work
 */
abstract class AbstractProcessorJob implements ProcessorJobInterface
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $input;

    protected $hoquJobId;

    /**
     * Create a new store job instance.
     *
     * @return void
     */
    public function __construct($fields)
    {
        $this->input = $fields['input'];
        $this->hoquJobId = $fields['hoqu_job_id'];
    }

    public function getInput()
    {
        return $this->input;
    }

    /**
     * Execute the job.
     * PLEASE: dont override this method
     *
     * @return void
     */
    public function handle(HoquClient $hoquClient)
    {
        try {
            $output = $this->process($this->getInput());
        } catch (Throwable|Exception $e) {
            $output = json_encode($e); //json encode exception to send it to hoqu
        } finally {
            $this->done($hoquClient, [
                'output' => $output,
                'hoqu_job_id' => $this->hoquJobId,
            ]); //on failure send to hoqu an exception
        }
    }

    /**
     * Uses the hoquClient service to send to hoqu the job output
     *
     * @param  array  $data
     * @return void
     */
    public function done(HoquClient $hoquClient, $data)
    {
        $response = $hoquClient->done($data);
        if (! $response->ok()) {
            throw new Exception('Something went wrong sending DONE to hoqu. Http status: '.$response->status());
        } elseif (isset($response['error'])) {
            throw new Exception('Something went wrong sending DONE to hoqu.'.$response['error']);
        }
    }

    abstract public function process($input);
}
