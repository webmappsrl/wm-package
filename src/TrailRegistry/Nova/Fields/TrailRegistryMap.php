<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Fields;

use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

/**
 * La mappa della scheda di un codice del registro: il settore da cui il
 * prefisso e' stato ricavato, il sentiero a cui il codice e' assegnato e,
 * quando c'e', la traccia dell'istanza da cui il codice e' nato.
 *
 * Stesso schema di `Osm2cai\SignageMap\SignageMap` in osm2cai2 — una
 * sottoclasse sottile di `FeatureCollectionMap` che fissa il comportamento e
 * lascia al modello la composizione delle feature — con una differenza
 * deliberata: **non dichiara un componente proprio**.
 *
 * SignageMap ne ha uno (`signage-map`) perche' gli serve un disegno diverso
 * sulla mappa, e quindi un bundle JavaScript compilato a parte. Qui il disegno
 * e' quello di sempre, quindi si eredita `feature-collection-map` e il suo
 * bundle gia' costruito: un componente nuovo vorrebbe dire una compilazione da
 * mantenere allineata, in cambio di nulla.
 *
 * Cosa disegna lo decide `TrailRegistryCode::getFeatureCollectionMap()`, che
 * la rotta del campo chiama per identificativo. E' la stessa divisione del
 * lavoro del resto del package: il modello compone, il campo mostra.
 */
class TrailRegistryMap extends FeatureCollectionMap
{
    public function __construct($name, $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        // Un codice non si modifica da un form, quindi la mappa non ha senso
        // altrove: e' una scheda di sola lettura.
        $this->onlyOnDetail();
    }

    /**
     * Punta la mappa a un endpoint diverso da quello predefinito.
     *
     * Serve quando la rotta standard non riesce a risalire al modello dal nome
     * della Resource: quella cerca in `App\Models` e `Wm\WmPackage\Models` e
     * ripiega sull'elenco delle Resource di Nova, e i modelli di un dominio
     * opzionale vivono altrove.
     *
     * @param  string  $url
     */
    public function geojsonUrl($url): static
    {
        return $this->withMeta(['geojsonUrl' => $url]);
    }

    public function height(int $height = 500): static
    {
        return $this->withMeta(['height' => $height]);
    }
}
