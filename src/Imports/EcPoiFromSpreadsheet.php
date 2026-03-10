<?php

namespace Wm\WmPackage\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Row;
use Wm\WmPackage\Imports\Processors\EcPoiRowProcessor;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi as PackageEcPoi;

/**
 * Import di righe EcPoi da Spreadsheet (Maatwebsite Excel); intestazioni e validazione allineate al template Nova.
 *
 * La logica di applicazione/validazione è delegata a {@see EcPoiRowProcessor}.
 */
class EcPoiFromSpreadsheet extends AbstractExcelSpreadsheetImporter
{
    /**
     * @var array<int, array{row: int|string, message: string}>
     */
    public array $errors = [];

    /**
     * @var array<int, array{row: int|string, id: int|string}>
     */
    public array $poiIds = [];

    public function onRow(Row $row): void
    {
        $rowIndexRaw = method_exists($row, 'getIndex') ? $row->getIndex() : null;
        $rowIndex = is_numeric($rowIndexRaw) ? (int) $rowIndexRaw : 0;

        $data = $row->toArray();
        if (! is_array($data) || $data === []) {
            return;
        }

        $data = self::normalizeKeys($data);

        $modelClass = config('wm-package.ec_poi_model', PackageEcPoi::class);
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            $modelClass = PackageEcPoi::class;
        }

        $id = self::normalizeCellValue($data['id'] ?? null);
        $isCreate = ($id === null || $id === '');

        $processor = new EcPoiRowProcessor;

        /** @var Model&PackageEcPoi|null $model */
        $model = null;
        if (! $isCreate) {
            $id = is_numeric($id) ? (int) $id : (string) $id;
            $model = $modelClass::query()->whereKey($id)->first();
            if (! $model) {
                $this->errors[] = [
                    'row' => $rowIndex,
                    'message' => "Poi with ID {$id} not found.",
                ];

                return;
            }
        } else {
            $model = new $modelClass;
            $userId = Auth::id();
            if ($userId && $model->getAttribute('user_id') === null) {
                $model->setAttribute('user_id', $userId);
            }

            if ($model->getAttribute('app_id') === null) {
                /** @var \App\Models\User|null $user */
                $user = Auth::user();
                $appId = null;

                if ($user && method_exists($user, 'apps')) {
                    $appId = $user->apps()->orderBy('id')->value('id');
                }

                if (! $appId) {
                    $appId = App::query()->orderBy('id')->value('id');
                }

                if ($appId) {
                    $model->setAttribute('app_id', $appId);
                }
            }
        }

        $validationError = $processor->validate($data);
        if ($validationError !== null) {
            $this->errors[] = [
                'row' => $rowIndex,
                'message' => $validationError,
            ];

            return;
        }

        $processor->apply($model, $data);

        if ($this->saveQuietly && method_exists($model, 'saveQuietly')) {
            $model->saveQuietly();
        } else {
            $model->save();
        }

        $processor->syncTaxonomyPoiTypes($model, $data);

        if ($isCreate) {
            $this->poiIds[] = [
                'row' => $rowIndex,
                'id' => (string) $model->getKey(),
            ];
        }
    }
}

