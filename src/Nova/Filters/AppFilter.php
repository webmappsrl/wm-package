<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Filters\Filter;
use Wm\WmPackage\Models\App;

class AppFilter extends Filter
{
    /**
     * The displayable name of the filter.
     *
     * @var string
     */
    public $name = 'App';

    /**
     * Apply the filter to the given query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  mixed  $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Request $request, $query, $value)
    {
        $model = $query->getModel();
        $table = $model->getTable();

        if(!Schema::hasColumn($table, 'app_id')){
            $ugcRelations = [
                'ugc_pois',
                'ugc_tracks',
            ];

            $availableRelations = array_filter($ugcRelations, fn ($relation) => method_exists($model, $relation));

            if (empty($availableRelations)) {
                return $query;
            }
            
            return $query->where(function ($builder) use ($availableRelations, $value) {
                foreach ($availableRelations as $relation) {
                    $builder->orWhereHas($relation, fn ($relationQuery) => $relationQuery->where('app_id', $value));
                }
            });
        }
        
        return $query->where('app_id', $value);
    }

    /**
     * Get the filter's available options.
     *
     * @return array
     */
    public function options(Request $request)
    {
        if ($request->user()->hasRole('Administrator')) {
            $apps = App::all();
        } else {
            $appIds = $request->user()->apps->pluck('sku')->toArray();
            $apps = App::whereIn('sku', $appIds)->get();
        }

        return $apps->pluck('id', 'name')->toArray();
    }
}
