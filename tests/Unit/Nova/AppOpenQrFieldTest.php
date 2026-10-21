<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\Text;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\App as AppResource;

// Il toggle dei deep link resta spento: acceso, scriverebbe sul registro SFTP condiviso.

beforeEach(function () {
    config(['wm-package.shard_name' => 'test_shard']);
    Storage::fake('pois');
    Storage::fake('wmfe');
    Storage::fake('conf');
});

function appOpenQrField(App $app): ?Text
{
    $method = new ReflectionMethod(AppResource::class, 'app_release_data_tab');
    $method->setAccessible(true);

    return collect($method->invoke(new AppResource($app)))
        ->first(fn ($field) => ($field->attribute ?? null) === 'app_open_qr_code');
}

it('release_tab_has_app_open_qr_field_only_on_detail_as_html', function () {
    $app = App::factory()->create([
        'android_store_link' => 'https://play.google.com/store/apps/details?id=it.webmapp.cammini',
    ]);

    $field = appOpenQrField($app);

    expect($field)->toBeInstanceOf(Text::class);
    expect($field->asHtml)->toBeTrue();
    expect($field->showOnDetail)->toBeTrue();
    expect($field->showOnIndex)->toBeFalse();
    expect($field->showOnCreation)->toBeFalse();
    expect($field->showOnUpdate)->toBeFalse();
});
