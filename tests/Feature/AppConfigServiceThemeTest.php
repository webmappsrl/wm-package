<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

it('config.json THEME contains all camelCase keys mapped from properties->theme snake_case', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#111111',
                'secondary_color' => '#222222',
                'tertiary_color' => '#333333',
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

it('config.json THEME excludes non-string values instead of leaking them into the output', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => ['#fff'],
                'secondary_color' => '#222222',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'secondary' => '#222222',
    ]);
});

it('config.json THEME excludes malformed color values while keeping valid 3-digit hex and font strings untouched', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => 'banana',
                'secondary_color' => '#12345',
                'tertiary_color' => '#abc',
                'font_family_header' => 'Roboto Slab',
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['THEME'])->toBe([
        'tertiary' => '#abc',
        'fontFamilyHeader' => 'Roboto Slab',
    ]);
});
