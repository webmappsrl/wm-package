<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;

class WmClient
{
  private function run()
  {
    return Http::withToken();
  }
}
