<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\CallerClientException;

/**
 * The perfect http client to call a caller instance
 * make magics wit Http facade authenticated
 */
class CallerClient
{
    /**
     * Returns an autenticathed Http facade
     *
     * @return \Illuminate\Http\Client\PendingRequest
     *
     * @throws \Wm\WmPackage\Exceptions\CallerClientException - when the caller token isn't stored
     */
    private function httpWithToken($token)
    {
        if (! $token) {
            throw new CallerClientException('Impossible make an authenticated call to client, the token is not available!');
        }

        return Http::withToken($token);
    }

    /**
     * Returns the endpoint of provided users
     *
     * @param  Wm\WmPackage\Model\User  $user
     * @return string
     */
    public function getEndpointByUser($user)
    {
        return $user->endpoint.'/api/wm-geobox/cll/';
    }

    /**
     * Send DONE DONE response to a caller
     *
     * @param  Wm\WmPackage\Model\User  $user  - User Model that represents the remote caller
     * @param  array  $what  - The job output for the caller
     * @return \Illuminate\Http\Client\Request
     */
    public function done($user, $what)
    {
        return $this->httpWithToken($user->hoqu_api_token)->acceptJson()->post($this->getEndpointByUser($user).'donedone', $what);
    }
}
