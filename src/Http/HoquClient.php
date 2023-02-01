<?php

namespace Wm\WmPackage\Http;

use Exception;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\HoquClientException;
use Wm\WmPackage\Services\HoquCredentialsProvider;

/**
 * The perfect http client to call hoqu
 * make magics wit Http facade authenticated
 */
class HoquClient
{
    /**
     * The service that handle hoku token
     *
     * @var \Wm\WmPackage\Services\HoquCredentialsProvider
     */
    protected $tokenProvider;

    public function __construct(HoquCredentialsProvider $tokenProvider)
    {
        $this->tokenProvider = $tokenProvider;
    }

    /**
     * Get the default hoqu api endpoint
     *
     * @return string
     */
    private function getHoquApiUrl(): string
    {
        return config('wm-package.hoqu_url')
            . '/api/hoqu/';
    }

    /**
     * Returns an autenticathed Http facade
     *
     * @return \Illuminate\Http\Client\PendingRequest
     *
     * @throws \Wm\WmPackage\Exceptions\HoquClientException - when the hoqu token isn't stored
     */
    private function httpWithToken($token = false)
    {
        $token = $token !== false ? $token : $this->tokenProvider->getToken();
        if (!$token) {
            throw new HoquClientException('Impossible make an authenticated call to hoqu, the token is not available!');
        }

        return Http::withToken($token);
    }

    // TODO:
    // public function done($what)
    // {
    //     return Http::postJson($this->getHoquApiUrl() . 'done', $what)->body();
    // }

    /**
     * The STORE call to hoqu
     *
     * @param  array  $what - the body to send as json that mush have these keys: name, input
     * @return array?
     */
    public function store($what)
    {
        return $this->httpWithToken()->acceptJson()->post($this->getHoquApiUrl() . 'store', $what)->json();
    }

    /**
     * The REGISTER LOGIN call to hoqu that use the "special" (that can register other users on hoku) user credentials
     * use the config keys:
     *
     * HOQU_REGISTER_USERNAME
     * HOQU_REGISTER_PASSWORD
     *
     * use the .env file to configure them
     * or `php artisan hoqu:create-register-user` command on hoqu if you need credentials
     *
     * @return array
     *
     * @throws Exception
     */
    public function registerLogin()
    {
        $url = $this->getHoquApiUrl() . 'register-login';
        $response = Http::acceptJson()->post($url, [
            'email' => config('HOQU_REGISTER_USERNAME', 'register@webmapp.it'),
            'password' => config('HOQU_REGISTER_PASSWORD', 'test'),
        ]);

        $json = $response->json();

        if (!isset($json['token'])) {
            //TODO: add specific exception
            throw new Exception("Something goes wrong during hoqu login ($url). Here the hoqu response status:" . $response->status());
        }

        return $json;
    }

    /**
     * Register a new (simple) user to Hoqu that can use api via token
     *
     * @param  string  $token
     * @param  array  $json - json in json_decoded format
     * @return mixed - can return array or scalar value
     */
    public function register($token, $json)
    {
        $response = $this->httpWithToken($token)->acceptJson()
            ->post($this->getHoquApiUrl() . 'register', $json);

        return $response->json();
    }
}
