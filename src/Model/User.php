<?php

namespace Wm\WmPackage\Model;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    public $endpoint;

    public $hoqu_api_token;

    public $password;
}
