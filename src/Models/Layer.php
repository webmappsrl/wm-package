<?php

namespace Wm\WmPackage\Models;

use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\Polygon;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMapTrait;
use Wm\WmPackage\Observers\LayerObserver;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Traits\HasPackageFactory;
use Wm\WmPackage\Traits\TaxonomyAbleModel;
use Wm\WmPackage\Traits\TaxonomyWhereAbleModel;

class Layer extends Polygon
{
    use FeatureCollectionMapTrait, HasPackageFactory, HasTranslations, TaxonomyAbleModel, TaxonomyWhereAbleModel;

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();
        Layer::observe(LayerObserver::class);

        // Imposta un default per properties se è null
        static::creating(function ($model) {
            if (is_null($model->properties)) {
                $model->properties = [];
            }

            if (App::count() === 1 && empty($model->app_id)) {
                $model->app_id = App::first()->id;
            }
        });
    }

    public array $translatable = ['name', 'properties->title', 'properties->subtitle', 'properties->description'];

    protected $casts = [
        'properties' => 'array',
        'configuration' => 'array',
    ];

    protected $fillable = [
        'name',
        'properties',
        'configuration',
        'app_id',
        'geometry',
        'feature_collection',
        'user_id',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    // protected $appends = ['query_string'];

    public function appOwner()
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function associatedApps()
    {
        return $this->belongsToMany(App::class, 'layer_associated_app');
    }

    /**
     * Get the user that owns the Layer.
     */
    public function layerOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ecTracks(): MorphToMany
    {
        $ecTrackModelClass = config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack');

        return $this->morphedByMany($ecTrackModelClass, 'layerable')->using(Layerable::class);
    }

    public function manualEcPois(): MorphToMany
    {
        return $this->morphedByMany(EcPoi::class, 'layerable')->using(Layerable::class);
    }

    public function taxonomyActivities(): MorphToMany
    {
        return $this->morphToMany(TaxonomyActivity::class, 'taxonomy_activityable')
            ->using(TaxonomyActivityable::class);
    }

    public function isAutoTrackMode(): bool
    {
        return ($this->configuration['track_mode'] ?? 'auto') === 'auto';
    }

    public function setTrackMode(string $mode): void
    {
        $configuration = $this->configuration ?? [];
        $configuration['track_mode'] = $mode;
        $this->configuration = $configuration;
        $this->save();
    }

    /**
     * Move to a model mutator
     * https://laravel.com/docs/11.x/eloquent-mutators#defining-a-mutator
     *
     * @param [type] $defaultBBOX
     * @return void
     */
    public function computeBB($defaultBBOX)
    {
        $bbox = GeometryComputationService::make()->getTracksBbox($this->ecTracks);
        try {
            $this->bbox = $bbox ?? $defaultBBOX;
            $this->save();
        } catch (Exception $e) {
            Log::channel('layer')->error('computeBB of layer with id: '.$this->id);
        }
    }

    /**
     * Get the name as a string with fallback logic.
     * Priority: Current locale -> Italian -> English -> First available language
     */
    public function getStringName(): string
    {
        $value = $this->getRawOriginal('name');

        // Se il valore è una stringa JSON, decodificalo
        if (is_string($value) && $this->isJsonString($value)) {
            $value = json_decode($value, true);
        }

        // Se il valore è già una stringa (non tradotto), restituiscilo
        if (is_string($value) && ! $this->isJsonString($value)) {
            return $value;
        }

        // Se è un array/oggetto con chiavi di lingua
        if (is_array($value)) {
            $currentLocale = app()->getLocale();

            // Prima priorità: lingua corrente
            if (isset($value[$currentLocale]) && ! empty($value[$currentLocale])) {
                return $value[$currentLocale];
            }

            // Seconda priorità: italiano
            if (isset($value['it']) && ! empty($value['it'])) {
                return $value['it'];
            }

            // Terza priorità: inglese
            if (isset($value['en']) && ! empty($value['en'])) {
                return $value['en'];
            }

            // Quarta priorità: prima lingua disponibile (non vuota)
            foreach ($value as $translation) {
                if (! empty($translation)) {
                    return $translation;
                }
            }
        }

        // Fallback finale
        return '';
    }

    /**
     * Controlla se una stringa è un JSON valido
     *
     * @param  string  $string  La stringa da controllare
     * @return bool True se è un JSON valido
     */
    private function isJsonString(string $string): bool
    {
        if (empty($string)) {
            return false;
        }

        // Deve iniziare con { o [
        $trimmed = trim($string);
        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return false;
        }

        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Mutator per sincronizzare il campo name con properties->name
     *
     * @param  mixed  $value
     * @return void
     */
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = $value;

        // Sincronizza anche con properties->name
        $properties = $this->properties ?? [];
        $properties['name'] = $value;
        $this->attributes['properties'] = json_encode($properties);
    }

    /**
     * Override del metodo save per sincronizzare name con properties->name
     */
    public function save(array $options = [])
    {
        // Se il campo name è stato modificato, sincronizza con properties->name
        if ($this->isDirty('name')) {
            $properties = $this->properties ?? [];
            $properties['name'] = $this->name;
            $this->properties = $properties;
        }

        return parent::save($options);
    }

    /**
     * Determine if the user is an administrator.
     *
     * @return bool
     */
    public function getQueryStringAttribute()
    {
        $query_string = '';

        if ($this->taxonomyThemes->count() > 0) {
            $query_string .= '&taxonomyThemes=';
            $identifiers = $this->taxonomyThemes->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }
        if ($this->taxonomyWheres->count() > 0) {
            $query_string .= '&taxonomyWheres=';
            $identifiers = $this->taxonomyWheres->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }
        if ($this->taxonomyActivities->count() > 0) {
            $query_string .= '&taxonomyActivities=';
            $identifiers = $this->taxonomyActivities->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }

        return $this->attributes['query_string'] = $query_string;
    }

    public function getFeatureCollectionMap(): array
    {
        $this->clearAdditionalFeaturesForMap();
        $layerProperties = is_array($this->properties) ? $this->properties : [];
        $strokeWidth = isset($layerProperties['stroke_width']) && is_numeric($layerProperties['stroke_width'])
            ? max(1, (int) $layerProperties['stroke_width'])
            : 2;
        $strokeOpacity = isset($layerProperties['stroke_opacity']) && is_numeric($layerProperties['stroke_opacity'])
            ? min(1, max(0, (float) $layerProperties['stroke_opacity']))
            : 1.0;
        $fillOpacity = isset($layerProperties['fill_opacity']) && is_numeric($layerProperties['fill_opacity'])
            ? min(1, max(0, (float) $layerProperties['fill_opacity']))
            : 0.3;
        $strokeColor = ! empty($layerProperties['color'])
            ? hexToRgba($layerProperties['color'], $strokeOpacity)
            : 'rgba(255, 0, 0, 1)';
        $fillColor = ! empty($layerProperties['fill_color'])
            ? hexToRgba($layerProperties['fill_color'], $fillOpacity)
            : 'rgba(255, 0, 0, 0.3)';

        $novaResourceName = 'ec-tracks';
        $tableName = config('wm-package.ec_track_table', 'ec_tracks');
        $trackIds = $this->ecTracks()->pluck($tableName.'.id')->toArray();
        $taxonomyIds = $this->taxonomyActivities->pluck('id')->toArray();

        // Fallback temporaneo in lettura: solo in auto e solo se ci sono taxonomy activities.
        if ($this->isAutoTrackMode() && ! empty($taxonomyIds) && empty($trackIds)) {
            $ecTrackModelClass = config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack');
            $appIds = array_values(array_unique(array_filter([
                $this->app_id,
                ...$this->associatedApps->pluck('id')->toArray(),
            ])));

            if (! empty($appIds)) {
                $fallbackQuery = $ecTrackModelClass::query()
                    ->whereIn('app_id', $appIds);

                if (method_exists($ecTrackModelClass, 'taxonomyActivities')) {
                    $fallbackQuery->whereHas('taxonomyActivities', fn ($q) => $q->whereIn('taxonomy_activities.id', $taxonomyIds));
                }

                $trackIds = $fallbackQuery->pluck('id')->toArray();

                // Mantiene coerente il pivot, così il dettaglio delle track riflette gli stessi layer.
                if (! empty($trackIds)) {
                    $now = now();
                    $syncPayload = [];
                    foreach ($trackIds as $trackId) {
                        $syncPayload[$trackId] = [
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    $this->ecTracks()->sync($syncPayload);
                }
            }
        }

        if (! empty($trackIds)) {
            $placeholders = implode(',', array_fill(0, count($trackIds), '?'));
            $sql = "SELECT id, name, ST_AsGeoJSON(geometry) as geometry FROM {$tableName} WHERE id IN ({$placeholders}) AND geometry IS NOT NULL";
            $rows = DB::select($sql, $trackIds);

            foreach ($rows as $ecTrack) {
                $geometry = json_decode($ecTrack->geometry, true);
                $nameData = json_decode($ecTrack->name, true);
                $ecTrackName = $nameData['it'] ?? (is_array($nameData) && ! empty($nameData) ? reset($nameData) : 'Nome non disponibile');

                if ($geometry) {
                    $this->addFeaturesForMap([[
                        'type' => 'Feature',
                        'geometry' => $geometry,
                        'properties' => [
                            'tooltip' => $ecTrackName,
                            'link' => url('nova/resources/'.$novaResourceName.'/'.$ecTrack->id),
                            'strokeColor' => $strokeColor,
                            'strokeWidth' => $strokeWidth,
                            'fillColor' => $fillColor,
                        ],
                    ]]);
                }
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $this->getAdditionalFeaturesForMap(),
        ];
    }

}
