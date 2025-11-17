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

        $EcTracks = DB::select($this->getFeaturesQuery(), [$this->id, EcTrack::class]);
        // Nova resource name per EcTrack - usa kebab-case del nome del modello
        $novaResourceName = 'ec-tracks';

        foreach ($EcTracks as $ecTrack) {
            $geometry = json_decode($ecTrack->geometry, true);

            // Decodifica il JSON del nome e estrai la traduzione italiana o la prima disponibile
            $nameData = json_decode($ecTrack->name, true);

            // Priorità: 1) Italiano, 2) Prima disponibile, 3) Nome non disponibile
            $ecTrackName = $nameData['it'] ?? (is_array($nameData) && ! empty($nameData) ? reset($nameData) : 'Nome non disponibile');

            if ($geometry) {
                $routeFeature = [
                    'type' => 'Feature',
                    'geometry' => $geometry,
                    'properties' => [
                        'tooltip' => $ecTrackName,
                        'link' => url('nova'.'/resources/'.$novaResourceName.'/'.$ecTrack->id),
                        'strokeColor' => 'red',
                        'strokeWidth' => 2,
                    ],
                ];
                $this->addFeaturesForMap([$routeFeature]);
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $this->getAdditionalFeaturesForMap(),
        ];
    }

    private function getFeaturesQuery()
    {
        // Ottieni il nome della tabella dalla configurazione, default è 'ec_tracks'
        $tableName = config('wm-package.ec_track_table', 'ec_tracks');

        $sql = "
        SELECT 
            hr.id,
            hr.name,
            hr.properties,
            ST_AsGeoJSON(hr.geometry) as geometry
        FROM {$tableName} hr
        INNER JOIN layerables l ON hr.id = l.layerable_id
        WHERE l.layer_id = ?
            AND l.layerable_type = ?
            AND hr.geometry IS NOT NULL
            AND hr.geometry != ''
        ORDER BY hr.id
    ";

        return $sql;
    }
}
