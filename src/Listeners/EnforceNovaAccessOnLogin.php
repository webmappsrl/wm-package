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

        // Nei test il controllo e' spento, per non rompere i login programmatici delle suite
        // (anche quelle degli shard). Un test che vuole verificare proprio questo blocco accende
        // `wm-package.enforce_nova_access_in_tests`: fuori dai test la chiave non ha effetto.
        if (app()->runningUnitTests() && ! config('wm-package.enforce_nova_access_in_tests', false)) {
            return;
        }

        // API login is used to issue Sanctum tokens and must not require Nova access.
        if ($this->request->is('api/*')) {
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
