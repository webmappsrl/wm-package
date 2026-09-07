<?php

namespace Wm\WmPackage\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use ChristianKuri\LaravelFavorite\Traits\Favoriteability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Auth\Impersonatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Wm\WmPackage\Nova\Filters\AppFilter;
use Wm\WmPackage\Traits\HasPackageFactory;

/**
 * Undocumented class
 *
 * @property string $name
 * @property string $email
 * @property array $sku
 * @property Carbon $last_login_at
 */
class User extends Authenticatable implements HasMedia, JWTSubject
{
    use Favoriteability, HasApiTokens, HasPackageFactory, HasRoles, Impersonatable, InteractsWithMedia, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'surname',
        'email',
        'password',
        'app_id',
        'properties',
    ];

    protected $guard_name = 'web';

    public const AVATAR_CONVERSION_SIZE = 150;

    // Naming coerente con MediaService::getMediaConversionNameByWidthAndHeight()
    // (usato per le gallerie EcTrack/EcPoi: "thumbnail_{width}_{height}") — stessa
    // convenzione, dimensione nel nome, senza però dipendere da quella classe.
    public const AVATAR_CONVERSION_NAME = 'avatar_'.self::AVATAR_CONVERSION_SIZE.'_'.self::AVATAR_CONVERSION_SIZE;

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
        'properties' => 'array',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['geopass', 'avatar_url'];

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

    public function layers(): HasMany
    {
        return $this->hasMany(Layer::class);
    }

    public function ugc_tracks(): HasMany
    {
        return $this->hasMany(UgcTrack::class);
    }

    /**
     * Limit users to those who own at least one UGC POI or UGC track for the given app.
     * Used by {@see AppFilter} when the table has no `app_id` column.
     * From a query builder: `Model::query()->getAppsFromUgc($appId)`.
     */
    public function scopeGetAppsFromUgc(Builder $query, mixed $appId): Builder
    {
        if (blank($appId)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($appId) {
            $q->whereHas('ugc_pois', fn (Builder $pois) => $pois->where('app_id', $appId))
                ->orWhereHas('ugc_tracks', fn (Builder $tracks) => $tracks->where('app_id', $appId));
        });
    }

    public function taxonomy_targets(): HasMany
    {
        return $this->hasMany(TaxonomyTarget::class);
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
    public static function getLoggedUser(): ?self
    {
        return isset(auth()->user()->id)
            ? self::find(auth()->user()->id)
            : null;
    }

    /**
     * defines the default roles of this app
     *
     * @param  User|null  $user
     */
    public static function isInDefaultRoles(self $user)
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
     * Determine if the user can impersonate another user.
     *
     * Composes (does not replace) Nova's native `viewNova` check — defense in depth:
     * only Administrator, and only if the base Nova permission also holds.
     */
    public function canImpersonate()
    {
        return $this->hasRole('Administrator') && Gate::forUser($this)->check('viewNova');
    }

    /**
     * Determine if the user can be impersonated.
     *
     * Requires Nova access (`access-nova` permission): every `nova-api/*` route, including
     * stop impersonating, requires the `viewNova` gate. Impersonating a user without
     * `access-nova` (e.g. Guest) would leave the administrator stuck with a 403 on any
     * Nova action, including "Stop impersonating".
     */
    public function canBeImpersonated()
    {
        return $this->can('access-nova');
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    /**
     * Crop quadrato per rendere l'avatar visivamente coerente (rotondo via CSS)
     * indipendentemente dall'aspect ratio della foto originale. Sincrono
     * (nonQueued): l'avatar deve essere corretto già nella risposta HTTP che
     * segue l'upload, senza dipendere dal worker Horizon.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion(self::AVATAR_CONVERSION_NAME)
            ->nonQueued()
            ->fit(Fit::Crop, self::AVATAR_CONVERSION_SIZE, self::AVATAR_CONVERSION_SIZE);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        $media = $this->getFirstMedia('avatar');

        if (! $media) {
            return null;
        }

        if ($media->hasGeneratedConversion(self::AVATAR_CONVERSION_NAME)) {
            return $media->getUrl(self::AVATAR_CONVERSION_NAME);
        }

        // Rete di sicurezza: se la conversion non è generabile (es. formato
        // immagine non supportato dal motore GD/Imagick), il media resta
        // comunque salvato e servito — non ritagliato, ma non un URL rotto.
        return $media->getUrl();
    }

    public function getMorphClass()
    {
        return 'App\\Models\\User';
    }

    /**
     * Return the user as array with privacy filtered by provided app id.
     */
    public function toAppArray($appId): array
    {
        $userArray = $this->toArray();

        if (! isset($userArray['properties'])) {
            $userArray['properties'] = [];
        }

        if (isset($userArray['properties']['privacy'])) {
            $privacy = $userArray['properties']['privacy'];
            $userArray['properties']['privacy'] = isset($privacy[$appId]) ? array_values($privacy[$appId]) : [];
        } else {
            $userArray['properties']['privacy'] = [];
        }

        unset($userArray['password']);

        return $userArray;
    }
}
