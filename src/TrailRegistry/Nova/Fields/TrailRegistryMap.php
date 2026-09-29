<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Fields;

use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

/**
 * La mappa della scheda di un codice del registro: il settore da cui il
 * prefisso e' stato ricavato, il sentiero a cui il codice e' assegnato,
 * l'istanza da cui e' nato e gli altri sentieri dello stesso settore,
 * etichettati con numero e variante.
 *
 * Stesso schema di `Osm2cai\SignageMap\SignageMap` in osm2cai2, **componente
 * proprio compreso**. Fino a oc:8568 questa classe ereditava il componente di
 * `FeatureCollectionMap`, perche' il disegno era quello di sempre: con i
 * sentieri vicini non lo e' piu' — servono etichette sulle linee, una soglia
 * di zoom sotto la quale spariscono e un'inquadratura iniziale che ignori i
 * vicini — e nessuno dei tre si esprime con le prop del componente condiviso.
 *
 * Il componente non lo duplica: lo usa come figlio e interviene sull'evento
 * `map-ready`. Il prezzo e' un secondo bundle da tenere allineato; in cambio
 * le mappe di TaxonomyWhere, Layer, FeatureCollection e TrailRegistryAnomaly
 * restano intatte.
 *
 * Cosa disegna lo decide `TrailRegistryCode::getFeatureCollectionMap()`, che
 * la rotta del campo chiama per identificativo: la rotta resta quella di
 * `feature-collection-map`, e' solo il disegno a cambiare.
 */
class TrailRegistryMap extends FeatureCollectionMap
{
    /**
     * Il componente proprio: vedi il docblock della classe per il perche'.
     *
     * @var string
     */
    public $component = 'trail-registry-map';

    public function __construct($name, $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        // Un codice non si modifica da un form, quindi la mappa non ha senso
        // altrove: e' una scheda di sola lettura.
        $this->onlyOnDetail();

        // Il profilo si accende Resource per Resource: la scheda delle
        // anomalie usa questo stesso campo e non lo vuole (oc:8662).
        $this->enableSlopeChart(false);
    }

    /**
     * Oltre alla geometria, espone la versione della mappa del modello: il
     * componente la aggiunge all'URL del GeoJSON, cosi' dopo un'Action Nova —
     * che rilegge la risorsa ma non ricrea il componente — l'URL cambia e la
     * mappa si riscarica da sola (oc:8662).
     */
    public function resolve($resource, ?string $attribute = null): void
    {
        parent::resolve($resource, $attribute);

        if (is_object($resource) && method_exists($resource, 'mapVersion')) {
            $this->withMeta(['mapVersion' => (string) $resource->mapVersion()]);
        }
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

    /**
     * Il livello di zoom oltre il quale i numeri dei sentieri vicini
     * compaiono scritti sulla mappa.
     *
     * Sotto la soglia le tracce restano disegnate e il numero si legge
     * passandoci sopra col mouse: non sparisce, smette solo di occupare spazio
     * quando le tracce sono troppe perche' le etichette stiano una accanto
     * all'altra.
     *
     * Vive qui e non in `config/wm-package.php` perche' e' una proprieta' di
     * questa mappa, non del progetto: una soglia buona per i sentieri di un
     * settore non lo e' per altri disegni. La configurazione si aggiunge il
     * giorno in cui un consumer chiede di cambiarla senza toccare codice.
     */
    public function labelMinZoom(int $zoom): static
    {
        return $this->withMeta(['labelMinZoom' => $zoom]);
    }
}
