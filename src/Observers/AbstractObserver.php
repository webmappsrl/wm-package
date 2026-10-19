<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractObserver
{
    public function saving($model)
    {
        self::syncNameIntoProperties($model);
    }

    /**
     * Copia le traduzioni di `name` in `properties.name` sui modelli con `name` traducibile.
     * Pubblica e statica perché la usa anche l'import da Geohub, che salva senza observer
     * (GeohubImportService::persistQuietly(), oc:8677).
     */
    public static function syncNameIntoProperties(Model $model): void
    {
        if (in_array('name', $model->translatable ?? [])) {
            $properties = $model->properties ?? [];
            $properties['name'] = $model->getTranslations('name');
            $model->setAttribute('properties', $properties);
        }
    }
}
