<?php

namespace Wm\WmPackage\Nova\Fields\LayerFeatures;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Fields\Field;
use Wm\WmPackage\Models\Layer;

class LayerFeatures extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'layer-features';


    public function __construct($name, $layer, string $modelClass, $attribute = null, $resolveCallback = null)
    {

        parent::__construct($name, $attribute, $resolveCallback);

        // Salva il modello come proprietà del campo
        $this->modelClass = $modelClass;

        // Assicuriamoci che $layer sia un'istanza di Layer
        if (! $layer instanceof Layer) {
            Log::error("LayerFeatures: Il parametro passato non è un'istanza di Layer.");

            return;
        }
        if (! class_exists($modelClass)) {
            Log::error('LayerFeatures: Il modello specificato non esiste: ' . $modelClass);

            return;
        }

        // Carica automaticamente le entità associate
        $this->loadEcFeatures($layer, $name, $modelClass);
    }

    public function fillModelWithData(object $model, mixed $value, string $attribute): void
    {
        // the save is done via api
    }

    public function loadEcFeatures($layer, $name, $modelClass)
    {
        $selectedFeatureIds = [];

        // Ottieni il nome corretto della relazione dal modello
        $model = new $modelClass;
        if (! method_exists($model, 'getLayerRelationName')) {
            Log::error('LayerFeatures: Il modello specificato non implementa l\'interfaccia LayerRelatedModel.');

            $relationName = 'ecTracks';
        } else {
            $relationName = $model->getLayerRelationName();
        }

        // Carica esplicitamente la relazione per assicurarsi che sia disponibile
        if ($layer->relationLoaded($relationName)) {
            $selectedFeatureIds = $layer->{$relationName}->pluck('id')->toArray();
        } else {
            // Se la relazione non è caricata, caricala esplicitamente
            // Specifica esplicitamente la tabella per evitare ambiguità nella colonna 'id'
            $tableName = (new $modelClass)->getTable();
            $selectedFeatureIds = $layer->{$relationName}()->select($tableName . '.id')->pluck('id')->toArray();
        }

        $model = new $modelClass;
        $modelName = $model->getLayerRelationName();

        $this->withMeta([
            'selectedEcFeaturesIds' => $selectedFeatureIds,
            'model' => $modelClass,
            'modelName' => $modelName,
            'layerId' => $layer->id,
            'modelClass' => $modelClass,  // Aggiungiamo anche modelClass per essere sicuri
            'model_class' => $modelClass  // Aggiungiamo anche model_class per essere sicuri
        ]);
    }
}
