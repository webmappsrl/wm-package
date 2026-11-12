<?php

namespace Wm\WmPackage\Tests\Feature\TrailRegistry\Support;

use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * La sottoclasse che uno shard puo' configurare al posto della domanda del
 * package (`wm-package.features.trail_registry.models.application`).
 *
 * Conta le creazioni nel proprio `booted()`: se il canale API usasse la classe
 * base, il contatore resterebbe a zero, ed e' cosi' che un test se ne accorge.
 */
class ShardTrailApplication extends TrailApplication
{
    public static int $created = 0;

    protected static function booted(): void
    {
        parent::booted();

        static::created(fn () => static::$created++);
    }
}
