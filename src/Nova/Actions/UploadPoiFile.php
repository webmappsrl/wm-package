<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Http\Requests\NovaRequest;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Wm\WmPackage\Imports\EcPoiFromCSV;

class UploadPoiFile extends Action
{
    use InteractsWithQueue, Queueable;

    public function handle(ActionFields $fields, Collection $models)
    {
        $file = $fields->file;
        if (! $this->isValidFile($file)) {
            return Action::danger(__('Please upload a valid file.'));
        }

        try {
            $spreadsheet = $this->loadSpreadsheet($file);
            $this->removeErrorsSheetIfPresent($spreadsheet);
            $worksheet = $spreadsheet->getActiveSheet();

            $fileHeadersNormalized = $this->getFileHeadersFromWorksheet($worksheet);
            $validHeaders = $this->getValidHeaders();
            $validHeadersOrdered = array_values($validHeaders);

            $structuralErrorRows = $this->buildStructuralErrorTable($validHeaders, $validHeadersOrdered, $fileHeadersNormalized);
            if (! empty($structuralErrorRows)) {
                $this->addErrorsSheet($spreadsheet, $structuralErrorRows);

                return $this->downloadUpdatedSpreadsheet($spreadsheet, true);
            }

            if (! $this->hasHeaders($worksheet)) {
                $this->addErrorsSheet($spreadsheet, [
                    [__('Type'), __('Detail')],
                    [__('File structure'), __('The first row must contain the column headers.')],
                ]);

                return $this->downloadUpdatedSpreadsheet($spreadsheet, true);
            }

            if (! $this->hasValidData($worksheet)) {
                $this->addErrorsSheet($spreadsheet, [
                    [__('Type'), __('Detail')],
                    [__('File structure'), __('The second row cannot be empty. Insert the POI data starting from the second row.')],
                ]);

                return $this->downloadUpdatedSpreadsheet($spreadsheet, true);
            }

            $importer = new EcPoiFromCSV;
            Excel::import($importer, $file);

            // Sheet 0 is the data sheet
            $dataSheet = $spreadsheet->getSheet(0);
            $this->processImportErrors($dataSheet, $importer->errors);
            $this->populatePoiIds($dataSheet, $importer->poiIds);

            if (! empty($importer->errors)) {
                $importErrorTable = $this->formatImportErrorsForSheet($importer->errors);
                $this->addErrorsSheet($spreadsheet, $importErrorTable);
            }

            return $this->downloadUpdatedSpreadsheet($spreadsheet, ! empty($importer->errors));
        } catch (\Throwable $e) {
            report($e);

            $serverErrorTable = $this->formatServerErrorForSheet();
            $spreadsheet = new Spreadsheet;
            $this->addErrorsSheet($spreadsheet, $serverErrorTable);
            $spreadsheet->removeSheetByIndex(0);

            return $this->downloadUpdatedSpreadsheet($spreadsheet, true);
        }
    }

    public function fields(NovaRequest $request): array
    {
        $validHeaders = implode(', ', $this->getValidHeaders());

        return [
            File::make('Upload File', 'file')
                ->help(
                    '<strong> Read the instruction below </strong>'
                    .'</br></br>'
                    .'Please upload a valid .xlsx file.'
                    .'</br><strong>The file must contain the following headers: </strong>'
                    .$validHeaders
                ),
        ];
    }

    private function isValidFile($file): bool
    {
        return ! empty($file);
    }

    private function loadSpreadsheet($file): Spreadsheet
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        return $reader->load($file);
    }

    /**
     * @return string[]
     */
    private function getValidHeaders(): array
    {
        $headers = config('wm-geohub-import.ecPois.validHeaders', []);
        if (! is_array($headers)) {
            return [];
        }

        return array_values(array_map(static fn ($h) => (string) $h, $headers));
    }

    private function removeErrorsSheetIfPresent(Spreadsheet $spreadsheet): void
    {
        for ($i = 0; $i < $spreadsheet->getSheetCount(); $i++) {
            if ($spreadsheet->getSheet($i)->getTitle() === PoiFileAction::ERRORS_SHEET_TITLE) {
                $spreadsheet->removeSheetByIndex($i);
                break;
            }
        }
    }

    /**
     * @return string[]
     */
    private function getFileHeadersFromWorksheet(Worksheet $worksheet): array
    {
        $headers = [];
        $lastColumn = $worksheet->getHighestColumn(1);
        if ($lastColumn === '') {
            return [];
        }

        for ($col = 'A'; $col <= $lastColumn; $col++) {
            $value = $worksheet->getCell($col.'1')->getValue();
            if ($value !== null && trim((string) $value) !== '') {
                $normalized = strtolower(trim((string) $value));
                $normalized = preg_replace('/\s+/', '_', $normalized);
                $headers[] = $normalized;
            }
        }

        return $headers;
    }

    /**
     * @param  string[]  $validHeaders
     * @param  string[]  $validHeadersOrdered
     * @param  string[]  $fileHeadersNormalized
     * @return array<int, array<int, string>>
     */
    private function buildStructuralErrorTable(array $validHeaders, array $validHeadersOrdered, array $fileHeadersNormalized): array
    {
        $rows = [];

        $missingColumns = array_diff($validHeaders, $fileHeadersNormalized);
        if (! empty($missingColumns)) {
            $rows[] = [__('Missing columns'), implode(', ', $missingColumns)];
        }

        $orderInFile = array_values(array_intersect($fileHeadersNormalized, $validHeaders));
        if ($orderInFile !== $validHeadersOrdered) {
            $rows[] = [
                __('Columns order'),
                __('The columns order is not correct.').' '.__('Expected order:').' '.implode(', ', $validHeadersOrdered),
            ];
        }

        if ($rows === []) {
            return [];
        }

        return array_merge([[__('Type'), __('Detail')]], $rows);
    }

    private function hasHeaders(Worksheet $worksheet): bool
    {
        $lastColumn = $worksheet->getHighestColumn(1);
        for ($col = 'A'; $col <= $lastColumn; $col++) {
            if ($worksheet->getCell($col.'1')->getValue() !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasValidData(Worksheet $worksheet): bool
    {
        $lastColumn = $worksheet->getHighestDataColumn(2);
        for ($col = 'B'; $col <= $lastColumn; $col++) {
            if ($worksheet->getCell($col.'2')->getValue() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{row: int|string, message: string}>  $importerErrors
     * @return array<int, array<int, string|int>>
     */
    private function formatImportErrorsForSheet(array $importerErrors): array
    {
        $rows = [[__('Row'), __('Reasons')]];
        foreach ($importerErrors as $err) {
            $rows[] = [$err['row'] ?? '', $err['message'] ?? ''];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function formatServerErrorForSheet(): array
    {
        return [
            [__('Type'), __('Detail')],
            [__('Error'), __('An error occurred while processing the file.')],
            [__('Verification'), __('Verify that the file is in a valid Excel (.xlsx) format and that the structure is correct.')],
        ];
    }

    /**
     * @param  Spreadsheet  $spreadsheet
     * @param  array<int, array<int, string|int|float>>  $tableRows
     */
    private function addErrorsSheet(Spreadsheet $spreadsheet, array $tableRows): void
    {
        $errorsSheet = $spreadsheet->createSheet();
        $errorsSheet->setTitle(PoiFileAction::ERRORS_SHEET_TITLE);

        $maxCol = 0;
        foreach ($tableRows as $rowIndex => $row) {
            $colIndex = 1;
            foreach ($row as $cellValue) {
                $colLetter = Coordinate::stringFromColumnIndex($colIndex);
                $errorsSheet->setCellValue($colLetter.($rowIndex + 1), $cellValue);
                $maxCol = max($maxCol, $colIndex);
                $colIndex++;
            }
        }

        $headerRange = 'A1:'.Coordinate::stringFromColumnIndex($maxCol).'1';
        $errorsSheet->getStyle($headerRange)->getFont()->setBold(true);

        for ($col = 1; $col <= $maxCol; $col++) {
            $errorsSheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }
    }

    /**
     * @param  Worksheet  $worksheet
     * @param  array<int, array{row: int|string, message: string}>  $errors
     */
    private function processImportErrors(Worksheet $worksheet, array $errors): void
    {
        $lastColumn = $worksheet->getHighestColumn(1);
        $errorColumn = $this->findOrCreateColumn($worksheet, PoiFileAction::ERROR_COLUMN_NAME, $lastColumn);
        $highestRow = $worksheet->getHighestRow();

        $this->clearPreviousErrors($worksheet, $errorColumn, $lastColumn, $highestRow, $errors);
        $this->addNewErrors($worksheet, $errorColumn, $lastColumn, $errors);
    }

    private function findOrCreateColumn(Worksheet $worksheet, string $header, string &$lastColumn): string
    {
        $headerNormalized = strtolower(trim($header));
        for ($col = 'A'; $col <= $lastColumn; $col++) {
            $cellValue = $worksheet->getCell($col.'1')->getValue();
            $cellValue = is_scalar($cellValue) ? strtolower(trim((string) $cellValue)) : '';
            if ($cellValue === $headerNormalized) {
                return $col;
            }
        }

        $newColumn = ++$lastColumn;
        $worksheet->setCellValue($newColumn.'1', $header);

        return $newColumn;
    }

    /**
     * @param  array<int, array{row: int|string, message: string}>  $errors
     */
    private function clearPreviousErrors(Worksheet $worksheet, string $errorColumn, string $lastColumn, int $highestRow, array $errors): void
    {
        for ($row = 2; $row <= $highestRow; $row++) {
            if (! $this->hasError($row, $errors)) {
                $worksheet->setCellValue($errorColumn.$row, '');
                $worksheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
            }
        }
    }

    /**
     * @param  array<int, array{row: int|string, message: string}>  $errors
     */
    private function hasError(int $row, array $errors): bool
    {
        foreach ($errors as $error) {
            if ((string) ($error['row'] ?? '') === (string) $row) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{row: int|string, message: string}>  $errors
     */
    private function addNewErrors(Worksheet $worksheet, string $errorColumn, string $lastColumn, array $errors): void
    {
        foreach ($errors as $error) {
            $r = (string) ($error['row'] ?? '');
            if ($r === '' || ! ctype_digit($r)) {
                continue;
            }
            $row = (int) $r;
            $worksheet->setCellValue($errorColumn.$row, (string) ($error['message'] ?? ''));
            $this->highlightErrorRow($worksheet, $row, $lastColumn);
        }
    }

    /**
     * @param  array<int, array{row: int|string, id: int|string}>  $poiIds
     */
    private function populatePoiIds(Worksheet $worksheet, array $poiIds): void
    {
        $lastColumn = $worksheet->getHighestColumn(1);
        $idColumn = $this->findOrCreateColumn($worksheet, 'id', $lastColumn);

        foreach ($poiIds as $poiId) {
            $r = (string) ($poiId['row'] ?? '');
            if ($r === '' || ! ctype_digit($r)) {
                continue;
            }
            $row = (int) $r;
            $worksheet->getCell($idColumn.$row)
                ->setValueExplicit(
                    (string) ($poiId['id'] ?? ''),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
        }
    }

    private function highlightErrorRow(Worksheet $worksheet, int $row, string $lastColumn): void
    {
        $worksheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB(PoiFileAction::ERROR_HIGHLIGHT_COLOR);
    }

    private function downloadUpdatedSpreadsheet(Spreadsheet $spreadsheet, bool $hasErrors)
    {
        $fileName = $this->saveUpdatedSpreadsheet($spreadsheet, $hasErrors);

        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(10),
            ['fileName' => $fileName]
        );

        return Action::redirect($signedUrl);
    }

    private function saveUpdatedSpreadsheet(Spreadsheet $spreadsheet, bool $hasErrors): string
    {
        // Ensure taxonomies sheet is present and up-to-date
        $referenceSheet = $spreadsheet->getSheetByName(PoiFileAction::TAXONOMIES_SHEET_TITLE);
        if (! $referenceSheet) {
            $referenceSheet = $spreadsheet->createSheet();
            $referenceSheet->setTitle(PoiFileAction::TAXONOMIES_SHEET_TITLE);
        }

        $taxonomiesData = (new PoiFileAction)->getTaxonomiesData();

        $header = PoiFileAction::buildTaxonomiesSheetHeader($taxonomiesData['languages']);
        $totalColumns = PoiFileAction::getTaxonomiesSheetColumnsCount($taxonomiesData['languages']);
        $lastColumn = Coordinate::stringFromColumnIndex($totalColumns);

        // Header
        $col = 1;
        foreach ($header as $headerValue) {
            $columnLetter = Coordinate::stringFromColumnIndex($col);
            $referenceSheet->setCellValue($columnLetter.'1', $headerValue);
            $col++;
        }
        $referenceSheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);

        // Data rows
        $dataRows = PoiFileAction::buildTaxonomiesSheetRows(
            $taxonomiesData['poiTypes'],
            $taxonomiesData['poiThemes'],
            $taxonomiesData['languages']
        );
        foreach ($dataRows as $index => $rowData) {
            $row = $index + 2;
            $col = 1;
            foreach ($rowData as $cellValue) {
                $columnLetter = Coordinate::stringFromColumnIndex($col);
                $referenceSheet->setCellValue($columnLetter.$row, $cellValue);
                $col++;
            }
        }

        for ($col = 1; $col <= $totalColumns; $col++) {
            $columnLetter = Coordinate::stringFromColumnIndex($col);
            $referenceSheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $uniqueId = now()->timestamp;
        $fileName = ($hasErrors ? "poi-file-errors-{$uniqueId}.xlsx" : "poi-file-imported-{$uniqueId}.xlsx");
        $path = storage_path('app/public/'.$fileName);
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        return $fileName;
    }
}

