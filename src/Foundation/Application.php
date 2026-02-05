<?php

namespace Wm\WmPackage\Foundation;



class Application extends \Illuminate\Foundation\Application
{

    public static function configure(?string $basePath = null)
    {
        $basePath = match (true) {
            is_string($basePath) => $basePath,
            default => static::inferBasePath(),
        };

        return (new Configuration\ApplicationBuilder(new static($basePath)))
            ->withKernels()
            ->withEvents()
            ->withCommands()
            ->withProviders();
    }

}
