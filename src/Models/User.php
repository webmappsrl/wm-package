<?php

namespace Wm\WmPackage\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use ChristianKuri\LaravelFavorite\Traits\Favoriteability;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Wm\WmPackage\Traits\HasPackageFactory;

/**
 * Undocumented class
 *
 * @property string $name
 * @property string $email
 * @property string $sku
 * @property \Illuminate\Support\Carbon $last_login_at
 */
class User extends Authenticatable implements JWTSubject
{
    use Favoriteability, HasApiTokens, HasPackageFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'sku',
        'app_id',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['geopass'];

    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    public function ecTracks(): HasMany
    {
        return $this->hasMany(EcTrack::class);
    }

    public function ugc_pois(): HasMany
    {
        return $this->hasMany(UgcPoi::class);
    }

    public function ecPois()
    {
        return $this->hasMany(EcPoi::class);
    }

    public function ugc_tracks(): HasMany
    {
        return $this->hasMany(UgcTrack::class);
    }

    public function taxonomy_targets(): HasMany
    {
        return $this->hasMany(TaxonomyTarget::class);
    }

    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'model', 'model_has_roles');
    }

    public function downloadableEcTracks(): BelongsToMany
    {
        return $this->belongsToMany(EcTrack::class, 'downloadable_ec_track_user');
    }

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

    /**
     * Get the current logged User
     */
    public static function getLoggedUser(): ?User
    {
        return isset(auth()->user()->id)
            ? User::find(auth()->user()->id)
            : null;
    }

    /**
     * defines the default roles of this app
     *
     * @param  User|null  $user
     */
    public static function isInDefaultRoles(User $user)
    {
        if ($user->hasRole('Author') || $user->hasRole('Contributor')) {
            return true;
        }

        return false;
    }

    /**
     * defines whether at least one app associated to the user has Dashboard show true or not
     *
     * @param  User|null  $user
     */
    public function hasDashboardShow($app_id = null)
    {
        $apps = $this->apps;
        $result = false;

        if ($app_id) {
            foreach ($apps as $app) {
                if ($app->id == $app_id) {
                    if ($app->dashboard_show == true) {
                        $result = true;
                    }
                }
            }

            return $result;
        }

        foreach ($apps as $app) {
            if ($app->dashboard_show == true) {
                $result = true;
            }
        }

        return $result;
    }

    /**
     * defines whether at least one app associated to the user has Classification show true or not
     *
     * @param  User|null  $user
     */
    public function hasClassificationShow($app_id = null)
    {
        $apps = $this->apps;
        $result = false;

        if ($app_id) {
            foreach ($apps as $app) {
                if ($app->id == $app_id) {
                    if ($app->classification_show == true) {
                        $result = true;
                    }
                }
            }

            return $result;
        }

        foreach ($apps as $app) {
            if ($app->classification_show == true) {
                $result = true;
            }
        }

        return $result;
    }

    /**
     * Determine if the user is an administrator.
     *
     * @return bool
     */
    public function getGeoPassAttribute()
    {
        $pass = $this->attributes['geopass'] = $this->password;

        return $pass;
    }
}
