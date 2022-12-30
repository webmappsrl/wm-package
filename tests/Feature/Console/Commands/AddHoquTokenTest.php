<?php

namespace Tests\Feature\Console\Commands;

use Wm\WmPackage\Tests\TestCase;

class AddHoquTokenTest extends TestCase
{
    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_hoqu_add_toke_command_existence()
    {
        $testToken = 'blablabla';
        $this->artisan('hoqu:add-token', ['token' => $testToken])->assertSuccessful();
    }
}
