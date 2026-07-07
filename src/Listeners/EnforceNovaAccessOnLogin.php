<?php

namespace Wm\WmPackage\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class EnforceNovaAccessOnLogin
{
    public function __construct(private Request $request) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        if ($event->user->can('access-nova')) {
            return;
        }

        Log::warning('Blocked web login attempt: user lacks access-nova permission', [
            'user_id' => $event->user->getAuthIdentifier(),
        ]);

        Auth::guard('web')->logout();

        if ($this->request->hasSession()) {
            $this->request->session()->invalidate();
            $this->request->session()->regenerateToken();
        }

        throw ValidationException::withMessages([
            Fortify::username() => [trans('auth.failed')],
        ]);
    }
}
