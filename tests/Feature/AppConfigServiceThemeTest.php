<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(TestCase::class, DatabaseTransactions::class);

it('config.json THEME contains all camelCase keys mapped from properties->theme snake_case', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#111111',
                'secondary_color' => '#222222',
                'tertiary_color' => '#333333',
                'success_color' => '#444444',
                'warning_color' => '#555555',
                'danger_color' => '#666666',
                'default_feature_color' => '#777777',
                'font_family_header' => 'Roboto Slab',
                'font_family_content' => 'Roboto',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'primary' => '#111111',
        'secondary' => '#222222',
        'tertiary' => '#333333',
        'success' => '#444444',
        'warning' => '#555555',
        'danger' => '#666666',
        'defaultFeatureColor' => '#777777',
        'fontFamilyHeader' => 'Roboto Slab',
        'fontFamilyContent' => 'Roboto',
    ]);
});

it('config.json THEME excludes keys with empty or null values', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#111111',
                'secondary_color' => '',
                'tertiary_color' => null,
                'font_family_header' => 'Roboto Slab',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'primary' => '#111111',
        'fontFamilyHeader' => 'Roboto Slab',
    ]);
});

it('config.json THEME is an empty array when properties->theme is missing, without throwing', function () {
    $app = App::factory()->createQuietly([
        'properties' => [],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([]);
});

it('config.json THEME is an empty array when properties->theme is a malformed non-array value, without throwing', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => 'not-an-array',
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([]);
});

it('config.json THEME reproduces the real camminiditalia data shape (only primary and default feature color set)', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#ef7821',
                'font_family_header' => null,
                'font_family_content' => null,
                'default_feature_color' => '#ef7821',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'primary' => '#ef7821',
        'defaultFeatureColor' => '#ef7821',
    ]);
});
