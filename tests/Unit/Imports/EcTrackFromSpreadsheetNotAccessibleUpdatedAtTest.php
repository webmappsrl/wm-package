<?php

namespace Wm\WmPackage\Tests\Unit\Imports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Row;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Wm\WmPackage\Imports\EcTrackFromSpreadsheet;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Tests\TestCase;

class EcTrackFromSpreadsheetNotAccessibleUpdatedAtTest extends TestCase
{
    private const KEY = EcTrack::NOT_ACCESSIBLE_UPDATED_AT;

    protected function setUp(): void
    {
        parent::setUp();
        // L'observer di EcTrack al save() legge il nome dello shard.
        config([
            'wm-package.shard_name' => 'webmapp',
            'filesystems.disks.s3.region' => 'eu-west-1',
            'filesystems.disks.wmfe.region' => 'eu-west-1',
            'wm-package.ec_track_model' => EcTrack::class,
            // Le colonne di not_accessible non sono fra quelle di default: l'upload le legge solo se dichiarate.
            'wm-excel-ec-import.ecTracks.validHeaders' => ['id', 'not_accessible', 'not_accessible_message'],
        ]);
        Storage::fake('wmfe');
        Storage::fake('s3');
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function import(array $data, bool $saveQuietly): void
    {
        $row = Mockery::mock(Row::class);
        $row->shouldReceive('toArray')->andReturn($data);

        (new EcTrackFromSpreadsheet($saveQuietly))->onRow($row);
    }

    private function rowFor(EcTrack $track): array
    {
        return ['id' => $track->id, 'not_accessible' => '1', 'not_accessible_message' => 'Frana'];
    }

    #[DataProvider('quietModes')]
    public function test_writes_the_date_when_a_spreadsheet_row_switches_not_accessible_on(bool $saveQuietly)
    {
        $track = EcTrack::factory()->createQuietly(['properties' => ['not_accessible' => false]]);

        $this->import($this->rowFor($track), $saveQuietly);
        $this->assertSame('2026-11-17T10:00:00+00:00', $track->fresh()->properties[self::KEY]);

        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'UTC'));
        $this->import($this->rowFor($track), $saveQuietly);
        $this->assertSame('2026-11-17T10:00:00+00:00', $track->fresh()->properties[self::KEY]);
    }

    public static function quietModes(): array
    {
        return [[true], [false]];
    }
}
