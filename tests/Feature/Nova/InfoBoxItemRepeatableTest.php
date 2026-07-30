<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;

uses(TestCase::class, DatabaseTransactions::class);

it('exposes one title KeyValue field and one content field per configured locale', function () {
    $locales = config('wm-tab-translatable.locales');
    $fields = InfoBoxItemRepeatable::make()->fields(NovaRequest::create('/'));

    $titleField = collect($fields)->first(fn ($f) => $f->attribute === 'title');
    expect($titleField)->toBeInstanceOf(KeyValue::class);

    $attributes = collect($fields)->map(fn ($f) => $f->attribute)->all();
    foreach ($locales as $locale) {
        expect($attributes)->toContain("content_{$locale}");
    }
});

it('sanitizes a malicious content payload on fill while keeping safe formatting', function () {
    $fields = InfoBoxItemRepeatable::make()->fields(NovaRequest::create('/'));
    $contentIt = collect($fields)->first(fn ($f) => $f->attribute === 'content_it');

    $model = new stdClass;
    $request = NovaRequest::create('/', 'PUT', [
        'content_it' => '<p onclick="alert(1)">Testo <script>alert(2)</script><b>sicuro</b></p>',
    ]);

    $callback = $contentIt->fill($request, $model);
    if (is_callable($callback)) {
        $callback();
    }

    expect($model->content_it)->toContain('<b>sicuro</b>');
    expect($model->content_it)->not->toContain('onclick');
    expect($model->content_it)->not->toContain('<script>');
});
