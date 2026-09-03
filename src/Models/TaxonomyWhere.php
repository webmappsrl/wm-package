<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMapTrait;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $geometry
 * @property array|null $properties
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaxonomyWhere extends Taxonomy
{
    use FeatureCollectionMapTrait;

    protected $fillable = ['name', 'geometry', 'properties'];

    protected static function booted(): void
    {
        // Un record creato a mano da Nova non ha sorgente esterna: la sorgente
        // e' la piattaforma stessa. Cosi' ogni record ha sempre un source ed e'
        // filtrabile da TaxonomyWhereSourceFilter.
        static::creating(function (self $taxonomyWhere) {
            $properties = $taxonomyWhere->properties ?? [];

            if (empty($properties['source'])) {
                $properties['source'] = self::platformSource();
                $taxonomyWhere->properties = $properties;
            }
        });
    }

    /**
     * Identifier derivato dalla sorgente del dato, mai dal nome quando un id di
     * sorgente e' disponibile: i nomi da OSMFeatures sono instabili (assenti o
     * in alfabeto non latino), gli id no.
     *
     * Senza id di sorgente (record legacy o creati a mano) si ripiega sul nome
     * e, in caso di omonimia, si aggiunge un contatore progressivo.
     */
    public function generateIdentifier(): ?string
    {
        // Il fallback al nome della piattaforma e' calcolato qui e non letto da
        // properties: l'observer che invoca questo metodo e' registrato in
        // boot() e gira PRIMA del listener creating() di booted(), quindi la
        // sorgente potrebbe non essere ancora stata timbrata.
        $source = $this->properties['source'] ?? self::platformSource();
        $sourceId = $this->getSourceId();

        if ($sourceId !== null) {
            return trim(((string) $source).'-'.$sourceId, '-');
        }

        $base = Str::slug(trim(((string) $source).'-'.((string) parent::generateIdentifier()), '-'), '-');

        return $base !== '' ? $this->withCollisionCounter($base) : null;
    }

    /**
     * Id del record presso la sorgente esterna, se presente.
     */
    public function getSourceId(): ?string
    {
        $sourceId = $this->properties['osmfeatures_id']
            ?? $this->properties['osm2cai_id']
            ?? null;

        return $sourceId !== null ? (string) $sourceId : null;
    }

    /**
     * Sorgente usata per i record creati dalla piattaforma stessa.
     */
    protected static function platformSource(): string
    {
        return Str::slug((string) config('app.name'), '-');
    }

    /**
     * Restituisce l'identifier libero piu' vicino alla base: "base" se non e'
     * gia' in uso, altrimenti "base-2", "base-3", e cosi' via.
     */
    protected function withCollisionCounter(string $base): string
    {
        $query = static::query()->where('identifier', 'like', $base.'%');

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        $taken = $query->pluck('identifier')->filter()->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $counter = 2;
        while (in_array($base.'-'.$counter, $taken, true)) {
            $counter++;
        }

        return $base.'-'.$counter;
    }

    public function getOsmfeaturesId(): ?string
    {
        return $this->properties['osmfeatures_id'] ?? null;
    }

    public function getAdminLevel(): ?int
    {
        $v = $this->properties['admin_level'] ?? null;

        return $v !== null ? (int) $v : null;
    }

    public function getSource(): ?string
    {
        return $this->properties['source'] ?? null;
    }

    protected function getRelationKey(): string
    {
        return 'whereable';
    }

    public function layers(): MorphToMany
    {
        return $this->morphedByMany(Layer::class, 'taxonomy_whereable', 'taxonomy_whereables', 'taxonomy_where_id')
            ->using(TaxonomyWhereable::class);
    }

    public function ecTracks(): MorphToMany
    {
        $ecTrackModel = config('wm-package.ec_track_model', EcTrack::class);

        return $this->morphedByMany($ecTrackModel, 'taxonomy_whereable', 'taxonomy_whereables', 'taxonomy_where_id')
            ->using(TaxonomyWhereable::class);
    }

    public function getFeatureCollectionMap(): array
    {
        $tooltip = is_array($this->name)
            ? ($this->name[app()->getLocale()] ?? $this->name['it'] ?? $this->name['en'] ?? (reset($this->name) ?: 'Taxonomy Where'))
            : ($this->name ?: 'Taxonomy Where');

        return $this->getFeatureCollectionMapFromTrait([
            'tooltip' => $tooltip,
            'strokeColor' => 'rgba(37, 99, 235, 1)',
            'strokeWidth' => 2,
            'fillColor' => 'rgba(37, 99, 235, 0.2)',
        ]);
    }
}
