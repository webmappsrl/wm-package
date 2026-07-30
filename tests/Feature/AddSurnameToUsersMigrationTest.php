<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

it('users table has a nullable surname column after migration', function () {
    expect(Schema::hasColumn('users', 'surname'))->toBeTrue();

    $columns = Schema::getColumns('users');
    $surnameColumn = collect($columns)->firstWhere('name', 'surname');
    expect($surnameColumn)->not->toBeNull();
    expect($surnameColumn['nullable'])->toBeTrue();
});
