<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(TestCase::class, DatabaseTransactions::class);

it('config.json does not throw on populated translations arrays', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => ['key' => 'valore'],
        'translations_en' => ['key' => 'value'],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['TRANSLATIONS'])->toBe([
        'it' => ['key' => 'valore'],
        'en' => ['key' => 'value'],
    ]);
});

it('config.json handles null translations languages', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => null,
        'translations_en' => null,
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['TRANSLATIONS'])->toBe([]);
});

it('config.json handles a single populated language', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => ['key' => 'valore'],
        'translations_en' => null,
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['TRANSLATIONS'])->toHaveKey('it');
    expect($config['TRANSLATIONS'])->not->toHaveKey('en');
});
