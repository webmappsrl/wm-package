<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src;

use Laravel\Nova\Fields\Field;

class FeatureCollectionMap extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'feature-collection-map';

    /**
     * Flag per abilitare l'arricchimento DEM
     */
    protected bool $demEnrichment = false;

    /**
     * Nome del componente popup personalizzato
     */
    protected ?string $popupComponent = null;

    /**
     * Create a new field.
     *
     * @param  string  $name
     * @param  string|callable|null  $attribute
     * @return void
     */
    public function __construct($name, $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        // Imposta automaticamente soloOnDetail
        $this->onlyOnDetail();
    }

    /**
     * Abilita l'arricchimento DEM per la FeatureCollection
     * Chiama l'endpoint point-matrix per aggiungere dati di elevazione e distanze
     *
     * @return $this
     */
    public function withDemEnrichment(bool $enabled = true)
    {
        $this->demEnrichment = $enabled;

        return $this->withMeta(['demEnrichment' => $enabled]);
    }

    /**
     * Permette di personalizzare l'URL del GeoJSON
     *
     * @param  string|callable  $url
     * @return $this
     */
    public function geojsonUrl($url)
    {
        return $this->withMeta(['geojsonUrl' => $url]);
    }

    /**
     * Permette di personalizzare l'altezza della mappa
     *
     * @return $this
     */
    public function height(int $height = 500)
    {
        return $this->withMeta(['height' => $height]);
    }

    /**
     * Abilita/disabilita i controlli zoom
     *
     * @return $this
     */
    public function showZoomControls(bool $enabled = true)
    {
        return $this->withMeta(['showZoomControls' => $enabled]);
    }

    /**
     * Abilita/disabilita lo zoom con la rotellina del mouse
     *
     * @return $this
     */
    public function mouseWheelZoom(bool $enabled = true)
    {
        return $this->withMeta(['mouseWheelZoom' => $enabled]);
    }

    /**
     * Abilita/disabilita il pan con il drag
     *
     * @return $this
     */
    public function dragPan(bool $enabled = true)
    {
        return $this->withMeta(['dragPan' => $enabled]);
    }

    /**
     * Imposta il padding per il fit della vista
     *
     * @return $this
     */
    public function padding(int $padding = 50)
    {
        return $this->withMeta(['padding' => $padding]);
    }

    /**
     * Imposta un componente popup personalizzato
     * Il componente deve essere registrato in Nova tramite Nova.booting()
     *
     * @param  string  $componentName  Nome del componente Vue registrato
     * @return $this
     */
    public function withPopupComponent(string $componentName)
    {
        $this->popupComponent = $componentName;

        return $this->withMeta(['popupComponent' => $componentName]);
    }

    /**
     * Prepare the field for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'demEnrichment' => $this->demEnrichment,
            'popupComponent' => $this->popupComponent,
        ]);
    }
}
