<?php

namespace Wm\WmPackage\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Abstracts\MultiLineString;

/**
 * Ricalcola il DEM mancante quando si apre il dettaglio (oc:8660).
 *
 * Va chiamato esplicitamente nel fields() della Resource, non messo nel
 * padre di tutte le Resource geometriche: fields() gira anche per
 * validazione e azioni, e un effetto nascosto li' e' difficile da trovare.
 */
trait DispatchesDemOnDetail
{
    protected function dispatchDemOnDetail(NovaRequest $request): void
    {
        // Il trait e' generico: nelle Resource attuali $this->resource e' sempre
        // una MultiLineString, ma il controllo resta per costruzione, a
        // protezione di una futura Resource che lo usasse su un altro model.
        // @phpstan-ignore instanceof.alwaysTrue
        if ($request->isResourceDetailRequest() && $this->resource instanceof MultiLineString) {
            $this->resource->dispatchDemIfMissing();
        }
    }
}
