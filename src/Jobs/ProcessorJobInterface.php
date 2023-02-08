<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Wm\WmPackage\Http\HoquClient;

interface ProcessorJobInterface extends ShouldQueue
{


  /**
   * A simple $input property getter
   *
   * @return void
   */
  function getInput();

  /**
   * Do the job with $this->getInput()
   *
   * @return any - returns the process output
   */
  function process($input);

  /**
   * Send the response to hoqu on process() completed
   *
   * @return void
   */
  function done(HoquClient $hoquClient, $output);

  /**
   * The main job function that execute process and done
   *
   * @return void
   */
  function handle(HoquClient $hoquClient);
}
