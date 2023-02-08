<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\ProcessorClientException;

/**
 * The perfect http client to call hoqu
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
     * Do a job on processor
     *
     * @param  \App\Models\User  $user - User Model that represents the remote processor
     * @param  array  $what - The input for the processor job
     * @return \Illuminate\Http\Client\Request
     */
    public function do($user, $what)
    {
        return $this->httpWithToken($user->hoqu_api_token)->acceptJson()->post($user->endpoint.'/api/wm/processor-do', $what);
    }
}
