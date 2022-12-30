<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Services\HoquTokenProvider;
use Wm\WmPackage\Exceptions\HoquClientException;

/**
 * The perfect http client to call hoqu
 * make magics wit Http facade authenticated
 */
class HoquClient
{

    /**
     * The service that handle hoku token
     *
     * @var \Wm\WmPackage\Services\HoquTokenProvider
     */
    protected $tokenProvider;

    public function __construct(HoquTokenProvider $tokenProvider)
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
        return config('HOQU_URL') . '/api/';
    }


    /**
     * Returns an autenticathed Http facade
     *
     * @return \Illuminate\Http\Client\PendingRequest
     * @throws \Wm\WmPackage\Exceptions\HoquClientException - when the hoqu token isn't stored
     */
    private function httpWithToken()
    {
        $token = $this->tokenProvider->getToken();
        if (!$token)
            throw new HoquClientException("Impossible make an authenticated call to hoqu, the token is not available!");

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
     * @param array $what - the body to send as json
     * @return array?
     */
    public function store($what)
    {
        return $this->httpWithToken()->acceptJson()->postJson($this->getHoquApiUrl() . 'store', $what)->json();
    }
}
