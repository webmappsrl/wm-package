<?php

declare(strict_types=1);

use Wm\WmPackage\Enums\WmfeTrackVersion;

/**
 * oc:8997 — le versioni del JSON della traccia su wmfe e quali sono attive su questo shard.
 */
it('restituisce le versioni dichiarate, nell\'ordine in cui sono dichiarate', function () {
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);

    expect(WmfeTrackVersion::active())->toBe([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1]);
});

it('tollera spazi attorno alle virgole e riduce i duplicati a uno', function () {
    config(['wm-package.wmfe_track_versions' => ' legacy , v1 , legacy ']);

    expect(WmfeTrackVersion::active())->toBe([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1]);
});

it('a dichiarazione assente lascia attiva la sola versione senza numero', function () {
    config(['wm-package.wmfe_track_versions' => null]);

    expect(WmfeTrackVersion::active())->toBe([WmfeTrackVersion::Legacy]);
});

it('tratta una dichiarazione vuota come assente', function (string $declaration) {
    config(['wm-package.wmfe_track_versions' => $declaration]);

    expect(WmfeTrackVersion::active())->toBe([WmfeTrackVersion::Legacy]);
})->with(['', '   ', ',,', ' , ']);

it('fallisce su una versione sconosciuta, dicendo quale e quali sono valide', function () {
    config(['wm-package.wmfe_track_versions' => 'legacy,v9']);

    expect(fn () => WmfeTrackVersion::active())
        ->toThrow(InvalidArgumentException::class);

    try {
        WmfeTrackVersion::active();
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('v9')
            ->and($e->getMessage())->toContain('legacy')
            ->and($e->getMessage())->toContain('v1');
    }
});

it('la versione corrente è l\'ultima conosciuta', function () {
    expect(WmfeTrackVersion::current())->toBe(WmfeTrackVersion::V1);
});

it('il prefisso di path della versione senza numero è vuoto', function () {
    expect(WmfeTrackVersion::Legacy->pathPrefix())->toBe('')
        ->and(WmfeTrackVersion::V1->pathPrefix())->toBe('v1/');
});
