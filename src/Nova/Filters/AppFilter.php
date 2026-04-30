<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
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

        // If the table doesn't have `app_id` (e.g. users), fall back to UGC relations via model scope.
        if (! $hasAppIdColumn && method_exists($model, 'scopeGetAppsFromUgc')) {
            return $query->getAppsFromUgc($value);
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
