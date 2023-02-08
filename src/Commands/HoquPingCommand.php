<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Http\HoquClient;

class HoquPingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoqu:ping';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Performs a call to Hoqu to check if local .env has a valid username/token';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(HoquClient $hoquClient)
    {
        $response = $hoquClient->ping();
        if ($response->ok()) {
            $this->info($response->body());
        } else {
            dump($response);
        }

        return 1;
    }
}
