<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\App;

class AppFilter extends Filter
{
    /**
     * The displayable name of the filter.
     *
     * @var string
     */
    public function name()
    {
        return __('App');
    }

    /**
     * Apply the filter to the given query.
     *
     * @param  Builder  $query
     * @param  mixed  $value
     * @return Builder
     */
    public function apply(Request $request, $query, $value)
    {
        if (blank($value)) {
            return $query;
        }

        $model = $query->getModel();

        $table = $model->getTable();
        static $tableHasAppId = [];
        $hasAppIdColumn = $tableHasAppId[$table] ??= Schema::hasColumn($table, 'app_id');

        // If the table doesn't have `app_id` (e.g. users in this project), fall back to UGC relations.
        if (!$hasAppIdColumn && method_exists($model, 'ugcPois') && method_exists($model, 'ugcTracks')) {
            return $query->where(function (Builder $q) use ($value) {
                $q->whereHas('ugcPois', function (Builder $ugcPois) use ($value) {
                    $ugcPois->where('app_id', $value);
                })->orWhereHas('ugcTracks', function (Builder $ugcTracks) use ($value) {
                    $ugcTracks->where('app_id', $value);
                });
            });
        }

        // Default behaviour for resources that have a direct `app_id` column (UGC, Layer, etc.).
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
