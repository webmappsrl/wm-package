<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Services\HoquTokenProvider;

class AddHoquToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoqu:add-token {token}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a persistent cache entry with the token provided to that user';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(HoquTokenProvider $tokenProvider)
    {
        $check = $tokenProvider->setToken($this->argument('token'));
        if ($check) {
            $this->info('Yeah, hoku token stored correctly!');

            return Command::SUCCESS;
        } else {
            $this->error('Ooops ... something goes wrong during token store');

            return Command::FAILURE;
        }
    }
}
