<?php

use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\Rules\OnlyAllowedKeys;

/**
 * La regola che rifiuta le chiavi non concordate dentro un oggetto, nominandole
 * (oc:9039). I valori restano alle regole dichiarate accanto — `in:`, `email`,
 * `string` — che li spiegano già e da cui Scramble ricava l'elenco per la
 * documentazione.
 */
function erroriConRegola(mixed $value, array $ammesse = ['name', 'email', 'subject']): array
{
    return Validator::make(
        ['soggetti' => $value],
        ['soggetti' => ['array'], 'soggetti.*' => ['array', new OnlyAllowedKeys($ammesse)]]
    )->errors()->toArray();
}

it('accetta un oggetto con le sole chiavi concordate', function () {
    expect(erroriConRegola([['name' => 'X', 'email' => 'a@b.it', 'subject' => 'organization']]))->toBe([]);
});

it('accetta un oggetto a cui mancano chiavi facoltative', function () {
    // La presenza di una chiave la chiedono `required`/`nullable` accanto,
    // non questa regola: qui si giudica solo cio' che e' stato mandato.
    expect(erroriConRegola([['name' => 'X']]))->toBe([]);
});

it('rifiuta una chiave non concordata nominandola', function () {
    $errori = erroriConRegola([['name' => 'X', 'cf' => 'RSSMRA80A01H501U']]);

    expect($errori)->toHaveKey('soggetti.0.cf')
        ->and($errori['soggetti.0.cf'][0])
        ->toContain('cf')
        ->toContain('name, email, subject');
});

it('nomina tutte le chiavi non concordate, non solo la prima', function () {
    $errori = erroriConRegola([['name' => 'X', 'telefono' => '070', 'pec' => 'a@pec.it']]);

    expect($errori)->toHaveKeys(['soggetti.0.telefono', 'soggetti.0.pec']);
});

it('indica la posizione nella lista, contata da zero', function () {
    $errori = erroriConRegola([
        ['name' => 'Primo', 'email' => 'a@b.it', 'subject' => 'organization'],
        ['name' => 'Secondo', 'cf' => 'RSSMRA80A01H501U'],
    ]);

    expect($errori)->toHaveKey('soggetti.1.cf')
        ->and($errori)->not->toHaveKey('soggetti.0.cf');
});

it('non si pronuncia su un valore che non e un oggetto', function () {
    // Lo dice la regola `array` accanto, con il suo messaggio: questa regola
    // non ne aggiunge un secondo sullo stesso errore.
    $errori = erroriConRegola(['una stringa']);

    expect(array_keys($errori))->toBe(['soggetti.0']);
});
