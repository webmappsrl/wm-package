<?php

namespace Wm\WmPackage\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class TestAction
{
    use AsAction;

    public string $commandSignature = 'wm-package:test';

    public function handle()
    {
        dump('Yeah! It works!');
    }
}
