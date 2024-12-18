<?php

namespace Wm\WmPackage\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

abstract class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function isValidatorForFormId($formId)
    {
        $formId = str_replace('_', ' ', $formId);
        //if form id is empty, return true
        if (empty($formId)) {
            return true;
        }
        //if permission does not exist, return true
        if (! Permission::where('name', 'validate '.$formId.'s')->exists()) {
            return true;
        }
        if ($formId === 'water') {
            return $this->hasPermissionTo('validate source surveys');
        }
        $permissionName = 'validate '.$formId;
        if (! str_ends_with($formId, 's')) {
            $permissionName .= 's';
        }

        return $this->hasPermissionTo($permissionName);
    }
}
