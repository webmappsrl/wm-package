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

        // Do not interfere with automated tests that use programmatic logins.
        if (app()->runningUnitTests()) {
            return;
        }

        // API login is used to issue Sanctum tokens and must not require Nova access.
        if ($this->request->is('api/*')) {
            return;
        }

        // Nova::impersonate()/stopImpersonating() effettuano un login interno (guard->login())
        // per passare all'utente impersonato o tornare all'impersonatore. In quel momento la
        // sessione ha già 'nova_impersonated_by' valorizzato (Nova lo scrive prima di chiamare
        // login()). L'autorizzazione per questi login è già garantita da canImpersonate()/
        // canBeImpersonated() a monte in ImpersonateController — questo gate non deve rivalutarla,
        // altrimenti un target senza 'access-nova' (es. ruolo Guest) farebbe fallire il login
        // interno e distruggerebbe la sessione dell'amministratore che sta impersonando.
        if ($this->request->hasSession() && $this->request->session()->has('nova_impersonated_by')) {
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
