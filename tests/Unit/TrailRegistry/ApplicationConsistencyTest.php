<?php

use Wm\WmPackage\TrailRegistry\ApplicationConsistency;

function consistency(): ApplicationConsistency
{
    return app(ApplicationConsistency::class);
}

/*
|--------------------------------------------------------------------------
| I campi che si mandano insieme
|--------------------------------------------------------------------------
*/

it('chiede le attivita a chi dichiara la primaria', function () {
    expect(consistency()->linkedFieldErrors(['primary_activity']))
        ->toHaveKey('activities');
});

it('chiede la primaria a chi dichiara le attivita', function () {
    expect(consistency()->linkedFieldErrors(['activities']))
        ->toHaveKey('primary_activity');
});

it('chiede le attivita a chi dichiara una difficolta', function () {
    expect(consistency()->linkedFieldErrors(['difficulty']))
        ->toHaveKey('activities');
});

it('non dice niente a chi li manda insieme', function () {
    expect(consistency()->linkedFieldErrors(['activities', 'primary_activity', 'difficulty']))
        ->toBe([]);
});

it('non dice niente a chi non ne manda nessuno', function () {
    // Una modifica che tocca altri campi non deve trascinarsi dietro i tre
    // campi legati: li pretendera' la conferma, se manca cio' che serve.
    expect(consistency()->linkedFieldErrors(['distance', 'from', 'to']))
        ->toBe([]);
});

it('la difficolta resta facoltativa dentro il gruppo', function () {
    expect(consistency()->linkedFieldErrors(['activities', 'primary_activity']))
        ->toBe([]);
});

/*
|--------------------------------------------------------------------------
| I valori che devono stare fra loro
|--------------------------------------------------------------------------
*/

it('rifiuta una primaria che non e fra le attivita proposte', function () {
    $errors = consistency()->valueErrors([
        'activities' => ['hiking'],
        'primary_activity' => 'cycling',
    ]);

    expect($errors)->toHaveKey('primary_activity')
        ->and($errors['primary_activity'][0])->toContain('cycling');
});

it('accetta una primaria fra le attivita proposte', function () {
    expect(consistency()->valueErrors([
        'activities' => ['hiking', 'mtb'],
        'primary_activity' => 'mtb',
    ]))->toBe([]);
});

it('rifiuta una difficolta dichiarata per un attivita non proposta', function () {
    expect(consistency()->valueErrors([
        'activities' => ['hiking'],
        'difficulty' => ['mtb' => 'MC'],
    ]))->toHaveKey('difficulty.mtb');
});

it('non giudica i valori quando le attivita non sono una lista', function () {
    // Senza il riferimento non c'e' confronto possibile, e l'errore vero e'
    // sulla forma di `activities`.
    expect(consistency()->valueErrors([
        'activities' => 'hiking',
        'primary_activity' => 'cycling',
    ]))->toBe([]);
});

it('non rigiudica un campo gia in errore', function () {
    expect(consistency()->valueErrors(
        ['activities' => ['hiking'], 'primary_activity' => 'cycling'],
        skip: ['primary_activity'],
    ))->toBe([]);
});

it('giudica anche senza nessuna chiamata dietro, come fara la conferma', function () {
    // E' il motivo dell'estrazione: alla conferma non c'e' un input HTTP, c'e'
    // il payload letto dal database.
    $apiData = ['activities' => ['hiking'], 'primary_activity' => 'cycling', 'distance' => 12.5];

    expect(consistency()->valueErrors($apiData))->toHaveKey('primary_activity');
});
