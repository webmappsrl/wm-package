<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;

class HoquClient
{
    private function getHoquApiUrl()
    {
        return config('HOQU_URL').'/api/';
    }

    // public function done($what)
    // {
    //     return Http::postJson($this->getHoquApiUrl() . 'done', $what)->body();
    // }

    public function store($what)
    {
        return Http::acceptJson()->post($this->getHoquApiUrl().'store', $what)->json();
    }
}
