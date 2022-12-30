<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Wm\WmPackage\Http\HoquClient;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Console\ConfirmableTrait;
use Wm\WmPackage\Services\HoquCredentialsProvider;

class HoquRegisterUserCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoqu:register-user';

    /**
     * The name of the console command.
     *
     * This name is used to identify the command during lazy loading.
     *
     * @var string|null
     *
     * @deprecated
     */
    protected static $defaultName = 'hoqu:register-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new user on Hoqu instance based on credetials provided in .env file';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(HoquClient $hoquClient, HoquCredentialsProvider $credentialsProvider)
    {

        $this->info('Registering/retrieving register user token on HOQU instance ...');
        $json = $hoquClient->registerLogin();
        $hoqu_register_token = $json['token']; //special token for register user on hoqu instance

        $this->info('Generate a random password and save it in .env file on this istance ...');
        $password = Str::random(20);
        //$passwordHash = Hash::make();
        $credentialsProvider->setPassword($password);

        $role = $this->anticipate("Are you a caller, a processor or both (the hoqu_roles on HOQU instance)?", [
            'caller',
            'processor',
            'caller,processor'
        ]);
        $roles = explode(',', $role);
        //TODO: validation of roles by enum

        $capability = $this->ask("Which are your classes capabilities (the hoqu_processor_capabilities on HOQU instance)? Separate them with comma (eg: \"AddLocationsToPoint,AddLocationsToPoint2\")");
        $capabilities = explode(',', $capability);
        //TODO? validation

        $endpoint = $this->ask("Where HOQU should call you (the endpoint on HOQU instance)? Eg: https://geohub2.webmapp.it/api/processor");

        /**
         * TODO: generate an user with token to send to HOKU
         * STEP 2
         */
        $instance_token = ''; ///TODO!

        $json = [
            'password' => $password,
            'hoqu_roles' => $roles,
            'hoqu_processor_capabilities' => $capabilities,
            'hoqu_api_token' => $instance_token,
            'endpoint' => $endpoint
        ];

        $this->info('Registering a caller/processor User on HOQU instance ...');
        $json = $hoquClient->register($hoqu_register_token, $json);

        $this->info('Storing the TOKEN received from HOQU in .env file ...');
        $credentialsProvider->setToken($json['token']);

        $this->info('Storing the USERNAME received from HOQU in .env file ...');
        $credentialsProvider->setUsername($json['user']['email']);

        return Command::SUCCESS;
    }
}
