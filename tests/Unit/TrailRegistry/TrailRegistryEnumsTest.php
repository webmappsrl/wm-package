<?php

use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

it('espone i tre stati del codice con i valori attesi', function () {
    expect(array_map(fn ($c) => $c->value, TrailCodeStatus::cases()))
        ->toBe(['reserved', 'assigned', 'released']);
});

it('considera attivi solo riservato e assegnato', function () {
    expect(TrailCodeStatus::active())
        ->toBe([TrailCodeStatus::Reserved, TrailCodeStatus::Assigned]);
});

it('espone i quattro stati dell istruttoria', function () {
    expect(array_map(fn ($c) => $c->value, TrailApplicationStatus::cases()))
        ->toBe(['under_compilation', 'under_review', 'rejected', 'approved']);
});

it('traduce ogni stato per l operatore', function () {
    foreach (TrailApplicationStatus::cases() as $status) {
        expect($status->label())->not->toBe($status->value);
    }
});
