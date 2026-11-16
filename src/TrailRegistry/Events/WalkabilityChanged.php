<?php

namespace Wm\WmPackage\TrailRegistry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;

/**
 * La percorribilita' di un sentiero e' cambiata (oc:8989).
 *
 * Serve a chi deve reagire senza che il package sappia chi sia: su forestas lo
 * ascolta il ricalcolo delle incongruenze catastali, che dipende anche da
 * questo dato e non puo' aspettare il prossimo import — una percorribilita'
 * registrata alle dieci resterebbe segnalata come mancante fino al giro dopo.
 */
class WalkabilityChanged
{
    use Dispatchable;

    public function __construct(
        public readonly EcTrack $track,
        public readonly TrailStatusEvent $event,
    ) {}
}
