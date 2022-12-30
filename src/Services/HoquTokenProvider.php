<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Cache;

class HoquTokenProvider
{
    /**
     * Store the token
     *
     * https://laravel.com/docs/9.x/cache#storing-items-forever
     *
     * @param  string  $token
     * @return bool
     */
    public function setToken($token)
    {
        return Cache::forever($this->getCacheKey(), $token);
    }

    /**
     * Get the hoqu token stored
     *
     * @return string|false - return the token if stored, false otherwise
     */
    public function getToken()
    {
        return Cache::get($this->getCacheKey(), false);
    }

    /**
     * Delete the token stored
     *
     * @return void
     */
    public function deleteToken()
    {
        return Cache::forget($this->getCacheKey());
    }

    /**
     * Get an unique key for the stored token
     *
     * @return void
     */
    private function getCacheKey()
    {
        return 'hoqu-token';
    }
}
