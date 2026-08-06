<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;

uses(TestCase::class, DatabaseTransactions::class);

it('exposes one title field and one content field, both FlexibleTranslatable', function () {
    $fields = InfoBoxItemRepeatable::make()->fields(NovaRequest::create('/'));

    expect($fields)->toHaveCount(2);
    expect($fields[0])->toBeInstanceOf(FlexibleTranslatable::class);
    expect($fields[1])->toBeInstanceOf(FlexibleTranslatable::class);
});
