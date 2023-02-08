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
    if (!$token) {
      throw new ProcessorClientException('Impossible make an authenticated call to processor, the token is not available!');
    }

    return Http::withToken($token);
  }

  // TODO: to test
  public function do($user, $what)
  {
    return $this->httpWithToken($user->hoqu_api_token)->acceptJson()->post($user->endpoint . 'processor-do', $what)->json();
  }
}
