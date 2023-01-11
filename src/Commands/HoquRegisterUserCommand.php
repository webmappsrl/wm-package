<?php

namespace Wm\WmPackage\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;
use Wm\WmPackage\Http\HoquClient;
use Wm\WmPackage\Services\HoquCredentialsProvider;

class HoquRegisterUserCommand extends Command implements Isolatable
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoqu:register-user
                            {--R|role= : required, the role of this instance: "caller" , "processor" or "caller,processor" }
                            {--endpoint=false : the endpoint of this instance, default is APP_URL in .env file}';

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
        $role = $this->option('role');
        $endpoint = $this->option('endpoint') ?? URL::to('/');

        $this->info('Registering/retrieving register user token on HOQU instance ...');
        $json = $hoquClient->registerLogin();
        $hoqu_register_token = $json['token']; //special token for register user on hoqu instance

        $this->info('Generate a random password and save it in .env file on this istance ...');
        $password = Str::random(20);
        //$passwordHash = Hash::make();
        $credentialsProvider->setPassword($password);

        // $role = $this->anticipate('Are you a caller, a processor or both (the hoqu_roles on HOQU instance)?', [
        //     'caller',
        //     'processor',
        //     'caller,processor',
        // ]);

        $roles = explode(',', $role);
        //TODO: validation of roles by enum

        //TODO: enable capabilities
        // $capability = $this->ask('Which are your classes capabilities (the hoqu_processor_capabilities on HOQU instance)? Separate them with comma (eg: "AddLocationsToPoint,AddLocationsToPoint2")');
        // $capabilities = explode(',', $capability);
        //TODO? validation

        // $endpoint = $this->ask('Where HOQU should call you (the endpoint on HOQU instance)? Eg: https://geohub2.webmapp.it/api/processor');

        /**
         * TODO: generate an user with token to send to HOKU
         * STEP 2
         */
        $instance_token = ''; ///TODO!

        $json = [
            'password' => $password,
            'hoqu_roles' => $roles,
            'hoqu_processor_capabilities' => $capabilities ?? [], //TODO: handle capabilities
            'hoqu_api_token' => $instance_token,
            'endpoint' => $endpoint,
        ];

        $this->info('Registering a caller/processor User on HOQU instance ...');
        $json = $hoquClient->register($hoqu_register_token, $json);

        $this->info('Storing the TOKEN received from HOQU in .env file ...');
        try {
            $credentialsProvider->setToken($json['token']);
        } catch (Throwable|Exception $e) {
            //TODO: add specific exception
            $this->error('Something goes wrong during hoqu registration. Here the hoqu response in json format:');
            $this->error(print_r($json, true));
            throw $e;
        }

        $this->info('Storing the USERNAME received from HOQU in .env file ...');
        $credentialsProvider->setUsername($json['user']['email']);

        return Command::SUCCESS;
    }
}
