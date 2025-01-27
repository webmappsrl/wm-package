<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;

class CaiClient
{
    protected function getHttpClient()
    {
        return Http::withBasicAuth($this->getAuthUsername(), $this->getAuthPassword());
    }

    protected function getAuthUsername()
    {
        return config('wm-package.clients.cai.basic_auth_usern');
    }

    protected function getAuthPassword()
    {
        return config('wm-package.clients.cai.basic_auth_password');
    }

    public function userIsACaiMember($fiscalCode)
    {
        $response = $this->getHttpClient()
            ->get($this->getIsMemberUrl($fiscalCode));

        return $response->successful() && $response->body() == 'true';
    }

    protected function getIsMemberUrl($fiscalCode)
    {
        return 'https://services.cai.it/cai-integration-ws/secured/ismember/'.$fiscalCode;
    }
}
