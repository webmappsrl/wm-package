<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Observers\TaxonomyObserver;

abstract class Taxonomy extends Polygon
{
    use HasFactory, HasTranslations;

    public array $translatable = ['name', 'description', 'excerpt'];

    protected $fillable = [
        'name',
        'import_method',
        'identifier',
        'properties',
        'description',
        'excerpt',
        'icon',
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'excerpt' => 'array',
        'properties' => 'array',
    ];

    abstract protected function getRelationKey(): string;

    /**
     * Genera l'identifier del modello. Il default usa la prima traduzione
     * disponibile del nome (preferenza: locale corrente, poi 'it', poi 'en').
     * I modelli figli possono sovrascriverlo con una regola propria.
     */
    public function generateIdentifier(): ?string
    {
        $translations = $this->getTranslations('name');

        foreach ([app()->getLocale(), 'it', 'en'] as $locale) {
            if (! empty($translations[$locale])) {
                return $translations[$locale];
            }
        }

        foreach ($translations as $value) {
            if (! empty($value)) {
                return $value;
            }
        }

        return null;
    }

    protected static function boot()
    {
        parent::boot();
        self::observe(TaxonomyObserver::class);
    }

    public function ecTracks(): MorphToMany
    {
        return $this->morphedByMany(EcTrack::class, 'taxonomy_'.$this->getRelationKey());
    }

    public function layers(): MorphToMany
    {
        return $this->morphedByMany(Layer::class, 'taxonomy_'.$this->getRelationKey());
    }

    public function ecPois(): MorphToMany
    {
        return $this->morphedByMany(EcPoi::class, 'taxonomy_'.$this->getRelationKey());
    }

    public function getJson(): array
    {
        $json = $this->removeUnnecessaryFields($this->toArray());

        if (isset($json['icon'])) {
            $json['icon_name'] = $json['icon'];
            unset($json['icon']);
        }

        return $json;
    }

    private function removeUnnecessaryFields(array $json): array
    {
        unset($json['pivot']);
        unset($json['import_method']);
        unset($json['source']);
        unset($json['source_id']);
        unset($json['user_id']);
        unset($json['created_at']);
        unset($json['updated_at']);
        unset($json['deleted_at']);
        unset($json['excerpt']);
        unset($json['properties']);

        return $json;
    }
}
