<?php

namespace Wm\WmPackage\Observers;

abstract class AbstractObserver
{
    public function saving($model)
    {
        if ($model->name && in_array('name', $model->translatable ?? [])) {
            $properties = $model->properties ?? [];
            $properties['name'] = $model->getTranslations('name');
            $model->properties = $properties;
        }
    }
}
