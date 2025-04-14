<?php

namespace Wm\WmPackage\Http\Clients\Abstracts;

use Illuminate\Support\Facades\Http;

abstract class JsonClient
{
    abstract protected function getHost(): string;

    protected function getHttpClient()
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json',
        ]);
    }
}
