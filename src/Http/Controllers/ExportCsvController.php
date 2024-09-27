<?php

namespace Wm\WmPackage\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Wm\WmPackage\Exports\GenericExport;

class ExportCsvController extends Controller
{
    public function showModelSelection()
    {
        $models = config('wm-csv-export.models');

        return view('wm-package::select-model', compact('models'));
    }

    public function handleModelSelection(Request $request)
    {
        $request->validate([
            'model' => 'required|string|in:'.implode(',', array_keys(config('wm-csv-export.models'))),
        ]);

        $model = $request->input('model');
        $models = config('wm-csv-export.models');

        return view('wm-package::select-filters', compact('model', 'models'));
    }

    public function exportModel(Request $request)
    {
        $request->validate([
            'model' => 'required|string|in:'.implode(',', array_keys(config('wm-csv-export.models'))),
        ]);

        $model = $request->input('model');
        $fields = config("wm-csv-export.models.$model.fields");
        $availableFilters = config("wm-csv-export.models.$model.available_filters");

        $filters = [];
        foreach ($availableFilters as $filter) {
            $field = $filter['field'];
            $value = $request->input("filters.$field");

            if ($value !== null && $value !== '') {
                $filters[] = [
                    'field' => $field,
                    'value' => $value,
                    'operator' => '=',
                ];
            }
        }

        $fileName = class_basename($model).'_export_'.now()->format('Ymd').'.csv';

        return Excel::download(new GenericExport($model, $fields, $filters), $fileName);
    }
}
