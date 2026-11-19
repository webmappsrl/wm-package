<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Wm\WmPackage\Enums\WmfeTrackVersion;

/**
 * Il GeoJSON Feature del dettaglio di una traccia, in una versione di formato (oc:8997).
 *
 * **Qui dentro non si calcola niente**: i dati li prende `EcTrackPropertiesBuilder`, che li
 * calcola una volta sola e restituisce le properties di ogni versione. Questa classe mette
 * insieme l'involucro — `type`, `geometry` — e serializza.
 *
 * **La serializzazione del file passa sempre di qui, mai da un `json_encode()` diretto.**
 * `JsonResource::resolve()` applica `filter()`, che reindicizza gli array annidati con chiavi
 * tutte numeriche: `taxonomy_where` con id numerici esce come lista JSON solo per quel passaggio,
 * e un `json_encode()` la trasformerebbe in un oggetto. Sarebbe una modifica di forma del file che
 * le app installate leggono, invisibile fino allo store.
 *
 * Senza versione vale quella senza numero, così ogni chiamante che c'era prima ottiene ciò che
 * otteneva.
 */
class EcTrackResource extends JsonResource
{
    private WmfeTrackVersion $version;

    private ?EcTrackPropertiesBuilder $builder;

    public function __construct($resource, ?WmfeTrackVersion $version = null, ?EcTrackPropertiesBuilder $builder = null)
    {
        parent::__construct($resource);

        $this->version = $version ?? WmfeTrackVersion::Legacy;
        $this->builder = $builder;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $builder = $this->builder ??= new EcTrackPropertiesBuilder($this->resource);

        return [
            'type' => 'Feature',
            'geometry' => $builder->geometry(),
            'properties' => $builder->build([$this->version])[$this->version->value],
        ];
    }
}
