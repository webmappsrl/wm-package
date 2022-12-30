<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
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
     * The service that handle hoqu token
     *
     * @var \Wm\WmPackage\Services\HoquTokenProvider
     */
    protected $tokenProvider;


    public function __construct(HoquTokenProvider $tokenProvider)
    {
        $this->tokenProvider = $tokenProvider;
    }
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(string $token)
    {

        $check = $this->tokenProvider->setToken($token);
        if ($check) {
            $this->info("Yeah, token stored correctly!");
            return Command::SUCCESS;
        } else {
            $this->error("Ooops ... something goes wrong during token store");
            return Command::FAILURE;
        }
    }
}
