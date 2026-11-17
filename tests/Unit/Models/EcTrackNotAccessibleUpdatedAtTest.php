<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Tests\TestCase;

class EcTrackNotAccessibleUpdatedAtTest extends TestCase
{
    private const KEY = EcTrack::NOT_ACCESSIBLE_UPDATED_AT;

    protected function setUp(): void
    {
        parent::setUp();
        // L'observer di EcTrack al save() legge il nome dello shard.
        config(['wm-package.shard_name' => 'webmapp', 'filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
        Storage::fake('wmfe');
        Storage::fake('s3');
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function track(array $properties): EcTrack
    {
        return EcTrack::factory()->createQuietly(['properties' => $properties])->fresh();
    }

    private function change(EcTrack $track, array $properties): EcTrack
    {
        $track->properties = array_merge($track->properties, $properties);
        $track->save();

        return $track->fresh();
    }

    public function test_writes_the_date_when_not_accessible_is_switched_on()
    {
        $track = $this->change($this->track(['not_accessible' => false]), ['not_accessible' => true]);

        $this->assertSame('2026-11-17T10:00:00+00:00', $track->properties[self::KEY]);
    }

    public function test_writes_the_date_when_the_italian_message_changes()
    {
        $track = $this->track(['not_accessible' => true, 'not_accessible_message' => ['it' => 'Frana']]);
        Carbon::setTestNow(Carbon::parse('2026-11-18 08:30:00', 'UTC'));

        $track = $this->change($track, ['not_accessible_message' => ['it' => 'Frana estesa']]);

        $this->assertSame('2026-11-18T08:30:00+00:00', $track->properties[self::KEY]);
    }

    public function test_keeps_the_date_when_switched_off()
    {
        $track = $this->track([
            'not_accessible' => true,
            'not_accessible_message' => ['it' => 'Frana'],
            self::KEY => '2026-01-01T00:00:00+00:00',
        ]);

        $track = $this->change($track, ['not_accessible' => false]);

        $this->assertSame('2026-01-01T00:00:00+00:00', $track->properties[self::KEY]);
    }

    public function test_keeps_the_date_when_only_english_changes()
    {
        $track = $this->track([
            'not_accessible' => true,
            'not_accessible_message' => ['it' => 'Frana', 'en' => 'Slide'],
            self::KEY => '2026-01-01T00:00:00+00:00',
        ]);

        $track = $this->change($track, ['not_accessible_message' => ['it' => 'Frana', 'en' => 'Landslide']]);

        $this->assertSame('2026-01-01T00:00:00+00:00', $track->properties[self::KEY]);
    }

    public function test_keeps_the_date_on_unrelated_changes()
    {
        $track = $this->track([
            'not_accessible' => true,
            'not_accessible_message' => ['it' => 'Frana'],
            self::KEY => '2026-01-01T00:00:00+00:00',
        ]);

        $track->name = ['it' => 'Altro nome'];
        $track->save();

        $this->assertSame('2026-01-01T00:00:00+00:00', $track->fresh()->properties[self::KEY]);
    }

    public function test_ignores_shape_change()
    {
        $track = $this->track([
            'not_accessible' => true,
            'not_accessible_message' => 'Frana',
            self::KEY => '2026-01-01T00:00:00+00:00',
        ]);

        $track = $this->change($track, ['not_accessible_message' => ['it' => 'Frana', 'en' => 'Landslide']]);

        $this->assertSame('2026-01-01T00:00:00+00:00', $track->properties[self::KEY]);
    }

    public function test_writes_the_date_on_a_new_track_created_with_save()
    {
        // L'observer in creazione accoda DEM e grafico altimetrico (sincroni in test): niente rete né node.
        Queue::fake();
        $track = EcTrack::factory()->make([
            'properties' => ['not_accessible' => true, 'not_accessible_message' => ['it' => 'Frana']],
        ]);
        $track->save();

        $this->assertSame('2026-11-17T10:00:00+00:00', $track->fresh()->properties[self::KEY]);
    }

    #[DataProvider('onValues')]
    public function test_is_not_accessible_on(mixed $value, bool $expected)
    {
        $this->assertSame($expected, EcTrack::isNotAccessibleOn($value));
    }

    public static function onValues(): array
    {
        return [
            [true, true], [1, true], ['1', true], ['true', true], ['TRUE', true],
            [false, false], [0, false], ['0', false], ['false', false], [null, false], ['', false],
        ];
    }
}
