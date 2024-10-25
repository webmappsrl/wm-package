<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Laravel\Nova\Actions\Action;
use Illuminate\Support\Collection;
use Laravel\Nova\Fields\ActionFields;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Nova\Http\Requests\NovaRequest;

class EditFields extends Action
{
    use InteractsWithQueue, Queueable;

    public $onlyOnIndex = true;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Edit';
    protected $fields = [];
    protected $resource = null;
    public function __construct($name = 'Edit', array $fields = [], $resource = null)
    {
        $this->fields = $fields;
        $this->name = __($name);
        $this->resource = $resource;
    }
    /**
     * Perform the action on the given models.
     *
     * @param  \Laravel\Nova\Fields\ActionFields  $fields
     * @param  \Illuminate\Support\Collection  $models
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $model) {
            foreach ($this->fields as $field) {
                if (isset($fields[$field])) {
                    $model->$field = $fields[$field];
                }
            }
            $model->save();
        }
    }

    /**
     * Get the fields available on the action.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        if ($this->resource) {
            $resourceInstance = new $this->resource($this->resource::newModel());
            $resourceFields = $resourceInstance->fields($request);

            $filteredFields = collect($resourceFields)
                ->filter(function ($field) {
                    return property_exists($field, 'attribute')
                        && property_exists($field, 'showOnUpdate')
                        && $field->showOnUpdate;
                })
                ->values()
                ->all();

            //if $this->fields is not empty, filter the fields
            if (!empty($this->fields)) {
                $filteredFields = collect($filteredFields)->filter(function ($field) {
                    return in_array($field->attribute, $this->fields);
                })->values()->all();
            }

            return $filteredFields;
        }

        return [];
    }

    public function name()
    {
        return $this->name;
    }
}
