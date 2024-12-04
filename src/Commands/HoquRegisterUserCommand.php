<?php

namespace Wm\WmPackage\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;
use Wm\WmPackage\Http\HoquClient;
use Wm\WmPackage\Model\User;
use Wm\WmPackage\Services\HoquCredentialsProvider;

class HoquRegisterUserCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoqu:register-user
                            {--R|role= : required, the role of this instance: "caller" , "processor" or "caller,processor" }
                            {--endpoint= : the endpoint of this instance, default is APP_URL in .env file}
                            {--capabilities=false : the endpoint of this instance, default is APP_URL in .env file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new user on Hoqu instance based on credetials provided in .env file';

    /**
     * Execute the console command.
     */
    public function handle(HoquClient $hoquClient, HoquCredentialsProvider $credentialsProvider): int
    {
        $role = $this->option('role') ?? 'caller';
        $endpoint = $this->option('endpoint') ?? URL::to('/');
        $capabilities = $this->option('capabilities') ?? [];

        $this->info('Registering/retrieving register user token on HOQU instance ...');
        $json = $hoquClient->registerLogin();

        if (! isset($json['token'])) {
            //TODO: add specific exception
            throw new Exception('Something goes wrong during hoqu login. Here the hoqu response in json format:' . print_r($json, true));
        }
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

        // $endpoint = $this->ask('Where HOQU should call you (the endpoint on HOQU instance)? Eg: https://geohub2.webmapp.it/api/processor');

        /**
         * STEP 2
         */
        $newUserFill = [
            'email' => 'hoqu@webmapp.it',
            'name' => 'Hoqu',
        ];

        // $capability = $this->ask('Which are your classes capabilities (the hoqu_processor_capabilities on HOQU instance)? Separate them with comma (eg: "AddLocationsToPoint,AddLocationsToPoint2")');

        $user = User::where($newUserFill)->first();

        if (! $user) {
            $newUserFill = array_merge($newUserFill, [
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(20)),
            ]);
            $user = User::create($newUserFill);
        }

        $instance_token = $user->createToken('default')->plainTextToken;

        if (! is_array($capabilities)) {
            $capabilities = explode(',', $capabilities);
        }

        $json = [
            'password' => $password,
            'hoqu_roles' => $roles,
            'hoqu_processor_capabilities' => $capabilities,
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
