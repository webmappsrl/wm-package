<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;

class HoquClient
{
    public function done($what)
    {
        return Http::get('http://google.com')->body();
    }

    public function store($what)
    {
        return Http::get('http://google.com')->body();
    }

    public function pull()
    {
        return Http::get('http://google.com')->body();
    }
}
