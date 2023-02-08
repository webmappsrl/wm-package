<?php

namespace Wm\WmPackage\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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

    /**
     * Create a new store job instance.
     *
     * @return void
     */
    public function __construct($input)
    {
        $this->input = $input;
    }

    public function getInput()
    {
        return $this->input;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(HoquClient $hoquClient)
    {
        try {
            $output = $this->process($this->getInput());
        } catch (Throwable | Exception $e) {
            $output = json_encode($e); //json encode exception to send it to hoqu
        } finally {
            $this->done($hoquClient, $output); //on failure send to hoqu an exception
        }
    }

    /**
     * Uses the hoquClient service to send to hoqu the job output
     *
     * @param HoquClient $hoquClient
     * @param [type] $output
     * @return void
     */
    public function done(HoquClient $hoquClient, $output)
    {
        $hoquClient->done($output);
    }



    abstract function process($input);
}
