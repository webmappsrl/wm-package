<?php

namespace Wm\WmPackage\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

/**
 * @method static \Illuminate\Log\Logger geohubImport()
 * @method static \Illuminate\Log\Logger exceptions()
 */
class WmLogger extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'wm.logger';
    }

    /**
     * Convenience method for the geohub-import channel
     *
     * @return \Illuminate\Log\Logger
     */
    public static function geohubImport()
    {
        return Log::channel('geohub-import');
    }

    /**
     * Convenience method for the package_exceptions channel
     *
     * @return \Illuminate\Log\Logger
     */
    public static function exceptions()
    {
        return Log::channel('package_exceptions');
    }

    /**
     * Convenience method for the failed_jobs channel
     *
     * @return \Illuminate\Log\Logger
     */
    public static function failedJobs()
    {
        return Log::channel('failed_jobs');
    }
}
