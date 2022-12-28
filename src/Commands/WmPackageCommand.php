<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;

class WmPackageCommand extends Command
{
    public $signature = 'wm-package';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
