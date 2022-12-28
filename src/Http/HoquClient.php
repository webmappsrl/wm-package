<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;

class HoquClient
{

  function done($what)
  {
    return Http::get('http://google.com')->body();
  }
  function store($what)
  {
    return Http::get('http://google.com')->body();
  }
  function pull()
  {
    return Http::get('http://google.com')->body();
  }
}
