<?php

namespace Wm\WmPackage\Observers;

abstract class AbstractObserver
{
    public function saving($model)
    {
        if ($model->name) {
            $properties = $model->properties ?? [];
            $properties['name'] = $model->name;
            $model->properties = $properties;
        }
    }
}
