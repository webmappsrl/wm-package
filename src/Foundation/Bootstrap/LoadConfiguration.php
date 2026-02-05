<?php

namespace Wm\WmPackage\Foundation\Bootstrap;

use Symfony\Component\Finder\Finder;

use Illuminate\Foundation\Bootstrap\LoadConfiguration as LaravelLoadConfiguration;
class LoadConfiguration extends LaravelLoadConfiguration
{


    /**
     * Get the base configuration files.
     *
     * @return array
     */
    protected function getBaseConfiguration()
    {
        $config = [];

        $wmPackageConfigDir = __DIR__.'/../../config';
        foreach (Finder::create()->files()->name('*.php')->in(__DIR__.'/../../../config') as $file) {

            $configFileName = basename($file->getRealPath(), '.php');
            $wmPackageConfigFileName = $wmPackageConfigDir.'/'.$configFileName;
            if (file_exists($wmPackageConfigFileName)) {
                $config[$configFileName] = require $wmPackageConfigFileName;
            } else {
                $config[$configFileName] = require $file->getRealPath();
            }
        }

        return $config;
    }

}
