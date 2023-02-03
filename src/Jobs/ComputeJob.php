<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Http\HoquClient as HttpHoquClient;

/**
 * StoreJob class
 *
 * The store job that validate input and start the HokuJob pipeline
 */
class ComputeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $input;

    /**
     * Create a new store job instance.
     *
     * @return void
     */
    public function __construct($input)
    {
        //TODO: VALIDATE INPUT STRING
        $this->input = $input;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(HttpHoquClient $hoquClient)
    {
        //TODO: call input service and produce the output
        $output = '{"result":"success"}';

        //TODO: send the output to hoqu
        $hoquClient->done($output);

        return true; //on success

        return false; //on failure
    }
}
