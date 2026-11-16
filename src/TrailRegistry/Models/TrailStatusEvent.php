<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;

/**
 * Un cambio di stato di un sentiero. Tabella in sola aggiunta: non si aggiorna
 * e non si cancella, e le righe le scrive solo il service della
 * classificazione (oc:8989).
 *
 * `taxonomy_id` dice di quale classificazione si parla: la percorribilita'
 * oggi, la validazione quando avra' anch'essa data e autore. Lo stato corrente
 * resta il legame nel pivot delle tassonomie; qui c'e' la storia.
 *
 * **Il nome della famiglia e' configurabile, questo nome di tabella no.** Chi
 * cambia `walkability_taxonomy` nella configurazione dello shard cambia quale
 * famiglia viene classificata, non dove ne finisce la storia.
 *
 * @property int $id
 * @property int $ec_track_id
 * @property int $taxonomy_id
 * @property int|null $from_term_id
 * @property int $to_term_id
 * @property string|null $description
 * @property int|null $user_id
 * @property TrailStatusEventSource $source
 * @property Carbon $created_at
 * @property-read EcTrack $ecTrack
 * @property-read TaxonomyTerm $taxonomy
 * @property-read TaxonomyTerm|null $fromTerm
 * @property-read TaxonomyTerm $toTerm
 * @property-read User|null $user
 */
class TrailStatusEvent extends Model
{
    protected $table = 'trail_status_events';

    public $timestamps = false;

    protected $fillable = [
        'ec_track_id',
        'taxonomy_id',
        'from_term_id',
        'to_term_id',
        'description',
        'user_id',
        'source',
        'created_at',
    ];

    protected $casts = [
        'source' => TrailStatusEventSource::class,
        'created_at' => 'datetime',
    ];

    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(EcTrack::class, 'ec_track_id');
    }

    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'taxonomy_id');
    }

    public function fromTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'from_term_id');
    }

    public function toTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'to_term_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
