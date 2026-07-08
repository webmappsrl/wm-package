<?php

namespace Wm\WmPackage\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobFailed;
use Laravel\Nova\Events\StartedImpersonating;
use Laravel\Nova\Events\StoppedImpersonating;
use Spatie\Backup\Events\BackupWasSuccessful;
use Wm\WmPackage\Events\OrderListReorderedEvent;
use Wm\WmPackage\Listeners\BackupCompletedListener;
use Wm\WmPackage\Listeners\EnforceNovaAccessOnLogin;
use Wm\WmPackage\Listeners\FailedJobsListener;
use Wm\WmPackage\Listeners\LogImpersonationStarted;
use Wm\WmPackage\Listeners\LogImpersonationStopped;
use Wm\WmPackage\Listeners\OrderListReorderedListener;
use Wm\WmPackage\Listeners\UpdateLastLoginAt;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        Login::class => [
            EnforceNovaAccessOnLogin::class,
            UpdateLastLoginAt::class,
        ],
        BackupWasSuccessful::class => [
            BackupCompletedListener::class,
        ],
        JobFailed::class => [
            FailedJobsListener::class,
        ],
        OrderListReorderedEvent::class => [
            OrderListReorderedListener::class,
        ],
        StartedImpersonating::class => [
            LogImpersonationStarted::class,
        ],
        StoppedImpersonating::class => [
            LogImpersonationStopped::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
