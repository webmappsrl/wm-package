<?php

namespace Tests\Unit\Imports;

use Maatwebsite\Excel\Row;
use Wm\WmPackage\Imports\AbstractExcelSpreadsheetImporter;
use Wm\WmPackage\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AbstractExcelSpreadsheetImporterTest extends TestCase
{
    #[Test]
    public function normalized_valid_headers_lowercase_and_underscores(): void
    {
        config([
            'wm-excel-ec-import.test_headers' => ['ID', 'Foo Bar', 'ele_from'],
        ]);

        $out = AbstractExcelSpreadsheetImporter::normalizedValidHeadersFromConfig('wm-excel-ec-import.test_headers');

        $this->assertSame(['id', 'foo_bar', 'ele_from'], $out);
    }

    #[Test]
    public function comma_separated_display_preserves_config_spelling(): void
    {
        config([
            'wm-excel-ec-import.test_display' => ['id', 'from', 'difficulty'],
        ]);

        $s = AbstractExcelSpreadsheetImporter::commaSeparatedValidHeadersForDisplay('wm-excel-ec-import.test_display');

        $this->assertSame('id, from, difficulty', $s);
    }

    #[Test]
    public function normalize_keys_trims_and_snake_cases_headers(): void
    {
        $importer = new class extends AbstractExcelSpreadsheetImporter
        {
            public function onRow(Row $row): void {}
        };

        $ref = new \ReflectionClass($importer);
        $m = $ref->getMethod('normalizeKeys');
        $m->setAccessible(true);

        $normalized = $m->invoke($importer, ['  Ele From ' => 10, 'TO' => 'x']);

        $this->assertSame(['ele_from' => 10, 'to' => 'x'], $normalized);
    }

    #[Test]
    public function normalize_cell_value_trims_empty_and_null_markers(): void
    {
        $importer = new class extends AbstractExcelSpreadsheetImporter
        {
            public function onRow(Row $row): void {}
        };

        $ref = new \ReflectionClass($importer);
        $m = $ref->getMethod('normalizeCellValue');
        $m->setAccessible(true);

        $this->assertNull($m->invoke($importer, '   '));
        $this->assertNull($m->invoke($importer, 'NULL'));
        $this->assertNull($m->invoke($importer, 'n/a'));
        $this->assertSame('ok', $m->invoke($importer, ' ok '));
    }
}
