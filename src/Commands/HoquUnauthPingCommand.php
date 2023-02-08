<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Http\HoquClient;

class HoquUnauthPingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoqu:unauth-ping';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Performs an unauthenticated call to Hoqu to check if hoqu block the response';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(HoquClient $hoquClient)
    {
        $response = $hoquClient->unAuthPing();
        if (! $response->ok()) {
            $this->info('Status code: '.$response->status());
            $this->info('Everithing ok!');
        } else {
            dump($response);
            $this->error('Something went wrong!');
        }

        return 1;
    }
}
