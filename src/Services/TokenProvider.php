<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HttpAuthenticationProvider
{
  function __construct($options = [])
  {
    $this->hostname = $options['hostname'] ?? '';
  }

  private function getToken(): string
  {
    return Str::random(40);
  }

  private function getTokenByIp(string $ip): string
  {
    return $this->getToken();
  }

  private function getTokenByHostname(string $hostname): string
  {
    return $this->getToken();
  }

  private function getTokenByName(string $name): string
  {
    return $this->getToken();
  }

  function httpWithAuth()
  {
    return Http::withToken($this->getToken());
  }
}
