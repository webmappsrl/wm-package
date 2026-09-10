<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeOrigin;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

/**
 * Una riga per ogni codice del registro.
 *
 * Il codice e' rappresentato dalle sue colonne, non da una stringa: `code` e
 * `fullCode` si calcolano e non si conservano, cosi' non c'e' nulla da tenere
 * allineato e la divergenza e' impossibile per costruzione.
 *
 * @property int $id
 * @property string $region
 * @property string $province
 * @property string $area
 * @property string $sector
 * @property int $number
 * @property string $variant
 * @property TrailCodeStatus $status
 * @property TrailCodeOrigin $origin
 * @property int|null $taxonomy_where_id
 * @property int|null $trail_application_id
 * @property int|null $ec_track_id
 * @property-read string $fullCode
 * @property-read string $code
 * @property-read string|null $denomination
 * @property-read TrailApplication|null $application
 * @property-read EcTrack|null $ecTrack
 * @property-read TaxonomyWhere|null $taxonomyWhere
 * @property-read Collection<int, TrailRegistryCodeEvent> $events
 */
class TrailRegistryCode extends Model
{
    use Concerns\ComposesTrailRegistryMap;

    protected $table = 'trail_registry_codes';

    protected $fillable = [
        'region',
        'province',
        'area',
        'sector',
        'number',
        'variant',
        'status',
        'origin',
        'taxonomy_where_id',
        'trail_application_id',
        'ec_track_id',
    ];

    protected $casts = [
        'number' => 'integer',
        'status' => TrailCodeStatus::class,
        'origin' => TrailCodeOrigin::class,
    ];

    /** Il full_code del settore: l'ambito in cui il numero e' unico. */
    protected function fullCode(): Attribute
    {
        return Attribute::get(
            fn () => $this->region.$this->province.$this->area.$this->sector,
        );
    }

    /**
     * Il codice in uscita. La variante `0` significa «senza variante» e non
     * compare mai: e' implicita.
     */
    protected function code(): Attribute
    {
        return Attribute::get(fn () => sprintf(
            '%s%02d%s',
            $this->fullCode,
            $this->number,
            $this->variant === '0' ? '' : $this->variant,
        ));
    }

    /**
     * Riferimento leggibile: il nome del sentiero se il codice e' assegnato,
     * altrimenti quello dell'istanza. E' l'unico appiglio in un elenco di
     * codici.
     */
    protected function denomination(): Attribute
    {
        return Attribute::get(
            fn () => $this->ecTrack?->getAttribute('name') ?? $this->application?->name,
        );
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(TrailApplication::class, 'trail_application_id');
    }

    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(EcTrack::class, 'ec_track_id');
    }

    public function taxonomyWhere(): BelongsTo
    {
        return $this->belongsTo(TaxonomyWhere::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TrailRegistryCodeEvent::class, 'trail_registry_code_id')
            ->orderBy('created_at');
    }

    /**
     * Le geometrie che spiegano questo codice, su una mappa sola.
     *
     * Tre feature, tutte facoltative perche' dipendono dallo stato:
     *
     * - il **settore** da cui il prefisso e' stato ricavato — e' il poligono
     *   che giustifica le prime cinque lettere del codice;
     * - il **sentiero** a cui il codice e' assegnato, che c'e' solo dopo
     *   l'approvazione;
     * - la traccia dell'**istanza** da cui il codice e' nato, che manca sui
     *   codici caricati dallo storico.
     *
     * Su un codice riservato si vedono settore e istanza; su uno assegnato
     * nato da una domanda si vedono tutti e tre, e la sovrapposizione fra la
     * traccia proposta e il sentiero accatastato dice a colpo d'occhio se
     * qualcosa e' cambiato per strada.
     *
     * Le geometrie si leggono con `ST_AsGeoJSON` in SQL e non via Eloquent:
     * nel package la geometria PostGIS non transita mai dall'ORM.
     *
     * @return array{type: string, features: array<int, array<string, mixed>>}
     */
    public function getFeatureCollectionMap(): array
    {
        $novaPath = $this->novaPath();

        $features = array_values(array_filter(array_merge(
            $this->sectorFeatures($novaPath),
            [
                $this->trackFeature($novaPath),
                $this->applicationFeature($novaPath),
            ],
        )));

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * I settori: quello scelto in evidenza, e gli altri che il tracciato
     * attraversa in tono minore, con la percentuale di percorso in ciascuno.
     *
     * Serve a mostrare **perche'** il prefisso e' quello: il settore si sceglie
     * dove il tracciato corre piu' a lungo, e senza gli altri sulla mappa quel
     * criterio resta invisibile. Non e' un caso di scuola — dei 580 sentieri
     * numerati, 64 attraversano due settori e 8 ne attraversano tre — ed e'
     * anche il modo per capire i casi in cui il settore dedotto dalla geometria
     * non coincide con quello scritto nel codice.
     *
     * Senza un sentiero o un'istanza da cui prendere la geometria, resta il
     * solo settore scelto: non c'e' un tracciato con cui intersecare.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sectorFeatures(string $novaPath): array
    {
        if ($this->taxonomy_where_id === null) {
            return [];
        }

        $geometrySource = $this->trackGeometrySource();

        if ($geometrySource === null) {
            $chosen = $this->sectorFeature($novaPath, $this->taxonomy_where_id, null, true, __('scelto'));

            return $chosen === null ? [] : [$chosen];
        }

        [$table, $id] = $geometrySource;

        // Le percentuali arrivano dal trait: una query sola, ordinata per
        // lunghezza dell'intersezione, la prima e' il settore che vince.
        $rows = $this->sectorsCrossedBy($table, $id);

        // I settori non scelti vanno disegnati **prima** di quello scelto.
        // Sulla mappa le feature si sovrappongono nell'ordine dell'elenco, e
        // le percentuali sono ordinate dalla piu' alta: lasciandole cosi', lo
        // scelto — che e' quasi sempre il primo — finirebbe sotto agli altri e
        // apparirebbe slavato proprio mentre deve risaltare.
        $others = [];
        $chosen = null;

        foreach ($rows as $row) {
            $isChosen = (int) $row->id === (int) $this->taxonomy_where_id;

            $feature = $this->sectorFeature(
                $novaPath,
                (int) $row->id,
                $row->percentuale === null ? null : (float) $row->percentuale,
                $isChosen,
                $isChosen ? __('scelto') : null,
            );

            if ($feature === null) {
                continue;
            }

            if ($isChosen) {
                $chosen = $feature;
            } else {
                $others[] = $feature;
            }
        }

        $features = $chosen === null ? $others : array_merge($others, [$chosen]);

        // Se il settore scelto non e' fra quelli che intersecano — puo'
        // accadere su un codice storico il cui sentiero e' stato ritracciato
        // dopo l'assegnazione — va mostrato lo stesso: e' quello che il codice
        // dichiara, e la sua assenza dall'elenco e' essa stessa un'anomalia da
        // vedere.
        $chosenIsThere = array_filter(
            $features,
            fn (array $f) => ($f['properties']['taxonomy_where_id'] ?? null) === (int) $this->taxonomy_where_id,
        );

        if ($chosenIsThere === []) {
            $fallback = $this->sectorFeature($novaPath, $this->taxonomy_where_id, null, true, __('scelto'));

            if ($fallback !== null) {
                $features[] = $fallback;
            }
        }

        return $features;
    }

    /**
     * Da dove prendere la geometria del tracciato per calcolare le
     * intersezioni: il sentiero se il codice e' assegnato, altrimenti
     * l'istanza.
     *
     * @return array{0: string, 1: int}|null
     */
    protected function trackGeometrySource(): ?array
    {
        if ($this->ec_track_id !== null) {
            return [config('wm-package.ec_track_table', 'ec_tracks'), (int) $this->ec_track_id];
        }

        if ($this->trail_application_id !== null) {
            return ['trail_applications', (int) $this->trail_application_id];
        }

        return null;
    }

    /**
     * Il sentiero accatastato: verde e spesso, e' il detentore del codice.
     *
     * @return array<string, mixed>|null
     */
    protected function trackFeature(string $novaPath): ?array
    {
        if ($this->ec_track_id === null) {
            return null;
        }

        $geometry = $this->geojsonFrom(config('wm-package.ec_track_table', 'ec_tracks'), $this->ec_track_id);

        if ($geometry === null) {
            return null;
        }

        return [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'tooltip' => __('Sentiero').' '.$this->code,
                'strokeColor' => 'rgba(22, 163, 74, 1)',
                'strokeWidth' => 4,
                'link' => url($novaPath.'/resources/'.static::novaUriKey('ec_track').'/'.$this->ec_track_id),
            ],
        ];
    }

    /**
     * La traccia dell'istanza: arancione e tratteggiata, per distinguerla dal
     * sentiero anche quando le due coincidono.
     *
     * @return array<string, mixed>|null
     */
    protected function applicationFeature(string $novaPath): ?array
    {
        if ($this->trail_application_id === null) {
            return null;
        }

        $geometry = $this->geojsonFrom('trail_applications', $this->trail_application_id);

        if ($geometry === null) {
            return null;
        }

        return [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'tooltip' => __('Istanza').' #'.$this->trail_application_id,
                'strokeColor' => 'rgba(234, 88, 12, 1)',
                'strokeWidth' => 3,
                'strokeDashArray' => [6, 6],
                'link' => url($novaPath.'/resources/'.static::novaUriKey('trail_application').'/'.$this->trail_application_id),
            ],
        ];
    }
}
