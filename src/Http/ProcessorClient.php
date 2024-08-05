<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\ProcessorClientException;

/**
 * The perfect http client to call a processor instance
 * make magics wit Http facade authenticated
 */
class ProcessorClient
{
    /**
     * Returns an autenticathed Http facade
     *
     * @return \Illuminate\Http\Client\PendingRequest
     *
     * @throws \Wm\WmPackage\Exceptions\ProcessorClientException - when the processor token isn't stored
     */
    private function httpWithToken($token)
    {
        if (! $token) {
            throw new ProcessorClientException('Impossible make an authenticated call to processor, the token is not available!');
        }

        return Http::withToken($token);
    }

    /**
     * Returns the endpoint of provided users
     *
     * @param  \App\Models\User  $user
     * @return string
     */
    public function getEndpointByUser($user)
    {
        return $user->endpoint.'/api/wm-geobox/prc/';
    }

    /**
     * Do a job on processor
     *
     * @param  \App\Models\User  $user  - User Model that represents the remote processor
     * @param  array  $what  - The input for the processor job
     * @return \Illuminate\Http\Client\Request
     */
    public function process($user, $what)
    {
        return $this->httpWithToken($user->hoqu_api_token)->acceptJson()->post($this->getEndpointByUser($user).'process', $what);
    }
}
