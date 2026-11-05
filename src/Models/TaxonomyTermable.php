<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Wm\WmPackage\Observers\TaxonomyTermableObserver;

/**
 * Relazione polimorfa fra un termine e il modello classificato (oc:8910).
 * parent_id sulla riga è la copia della tassonomia del termine: non è scrivibile a mano.
 */
class TaxonomyTermable extends MorphPivot
{
    protected $table = 'taxonomy_termables';

    public $incrementing = true;

    public $timestamps = false;

    protected $casts = [
        'properties' => 'array',
    ];

    protected static function booted(): void
    {
        static::observe(TaxonomyTermableObserver::class);

        static::saving(function (self $pivot) {
            // Ignora qualunque valore arrivato da fuori: la tassonomia si legge sempre dal termine.
            $pivot->parent_id = TaxonomyTerm::query()
                ->whereKey($pivot->taxonomy_term_id)
                ->value('parent_id');
        });
    }
}
