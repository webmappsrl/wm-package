<?php

namespace Wm\WmPackage\Imports;

use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Row;
use Wm\WmPackage\Imports\Processors\EcTrackRowProcessor;
use Wm\WmPackage\Models\EcTrack as PackageEcTrack;

/**
 * Import di righe EcTrack da Spreadsheet (Maatwebsite Excel). Aggiorna solo track esistenti per ID.
 *
 * La logica di applicazione della riga sul modello è delegata a {@see EcTrackRowProcessor}.
 */
class EcTrackFromSpreadsheet extends AbstractExcelSpreadsheetImporter
{
    public function onRow(Row $row): void
    {
        $data = $row->toArray();
        if (! is_array($data) || $data === []) {
            return;
        }

        $data = self::normalizeKeys($data);

        $modelClass = config('wm-package.ec_track_model', PackageEcTrack::class);
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            $modelClass = PackageEcTrack::class;
        }

        $id = self::normalizeCellValue($data['id'] ?? null);
        if ($id === null || $id === '') {
            throw new \InvalidArgumentException('Invalid track ID found. Please check the file and try again.');
        }

        $id = is_numeric($id) ? (int) $id : (string) $id;

        /** @var Model&PackageEcTrack|null $model */
        $model = $modelClass::query()->whereKey($id)->first();
        if (! $model) {
            throw new \InvalidArgumentException("Track with ID {$id} not found. Import updates existing tracks only.");
        }

        (new EcTrackRowProcessor)->apply($model, $data);

        if ($this->saveQuietly && method_exists($model, 'saveQuietly')) {
            $model->saveQuietly();
        } else {
            $model->save();
        }
    }
}
