<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Models\EcTrack;

beforeEach(function () {
    // Evita che EcTrackObserver::created dispatchi la chain di job (DEM, AWS, ecc.)
    Bus::fake();

    $fakeS3 = [
        'driver' => 's3',
        'key' => 'testing',
        'secret' => 'testing',
        'region' => 'eu-west-1',
        'bucket' => 'test',
        'url' => 'http://127.0.0.1:9000',
        'endpoint' => 'http://127.0.0.1:9000',
        'use_path_style_endpoint' => true,
    ];
    config([
        'scout.driver' => null,
        'wm-package.shard_name' => 'webmapp',
        'filesystems.disks.s3' => $fakeS3,
        'filesystems.disks.wmfe' => array_merge($fakeS3, ['bucket' => 'wmfe']),
        'filesystems.disks.wmdumps' => array_merge($fakeS3, ['bucket' => 'wmdumps']),
        'filesystems.disks.s3-osfmedia' => array_merge($fakeS3, ['bucket' => 'osfmedia']),
    ]);
});

/** @return array<string, mixed> */
function ecTrackTestProperties(array $overrides = []): array
{
    return array_merge([
        'description' => 'Test description',
        'excerpt' => 'Test excerpt',
        'difficulty' => 'facile',
        'rating' => 3,
        'distance' => 5.0,
        'ascent' => 100,
        'descent' => 50,
        'duration_forward' => 1.0,
        'duration_backward' => 1.0,
        'cai_scale' => 'T',
        'ref' => 'AB-123',
        'color' => '#FF0000',
        'created_at' => now()->toDateTimeString(),
    ], $overrides);
}

it('uses properties from and to when present', function () {
    $from = 'Partenza Prop';
    $to = 'Arrivo Prop';

    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => ecTrackTestProperties([
            'from' => $from,
            'to' => $to,
        ]),
    ]);

    $track->setAttribute('osmfeatures_data', [
        'properties' => [
            'from' => 'Da osmfeatures',
            'to' => 'A osmfeatures',
        ],
    ]);

    $arr = $track->toSearchableArray();

    expect($arr['from'])->toBe($from)
        ->and($arr['to'])->toBe($to);
});

it('falls back to osmfeatures_data properties when from or to missing in properties', function () {
    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => ecTrackTestProperties(),
    ]);

    $track->setAttribute('osmfeatures_data', [
        'properties' => [
            'from' => 'Solo OSF',
            'to' => 'Fine OSF',
        ],
    ]);

    $arr = $track->toSearchableArray();

    expect($arr['from'])->toBe('Solo OSF')
        ->and($arr['to'])->toBe('Fine OSF');
});

it('returns empty strings when from and to are absent everywhere', function () {
    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => ecTrackTestProperties(),
    ]);

    $arr = $track->toSearchableArray();

    expect($arr['from'])->toBe('')
        ->and($arr['to'])->toBe('');
});
