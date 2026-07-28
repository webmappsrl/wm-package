<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Jobs\TranslateModelJob;
use Wm\WmPackage\Models\EcTrack;

uses(RefreshDatabase::class);

/**
 * Http::fake dinamico: rimanda ogni campo ricevuto prefissato con "TRANSLATED:",
 * così ogni risposta riflette esattamente cosa è stato effettivamente richiesto.
 */
function fakeEchoTranslation(): void
{
    Http::fake(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = json_decode($body['messages'][1]['content'], true);
        $translated = array_map(fn ($value) => 'TRANSLATED:'.$value, $userMessage);

        return Http::response([
            'choices' => [
                ['message' => ['content' => json_encode($translated)]],
            ],
        ]);
    });
}

beforeEach(function () {
    config(['wm-package.clients.openai.api_key' => 'test-key']);
});

it('non fa nulla se name e description sono già completi (nessun campo extra)', function () {
    $track = EcTrack::factory()->createQuietly([
        'name' => ['it' => 'Nome', 'en' => 'Name', 'de' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'],
        'properties' => [
            // 'name' va mirroreto anche qui: getMissingLocales() per il campo 'name' richiede
            // che sia presente SIA in properties SIA nella colonna Spatie (stesso comportamento
            // preesistente, non introdotto da questo refactor) — riflette lo stato reale dei
            // record in produzione, dove properties['name'] è sempre sincronizzato dall'observer.
            'name' => ['it' => 'Nome', 'en' => 'Name', 'de' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'],
            'description' => ['it' => 'Testo', 'en' => 'Text', 'de' => 'Text', 'fr' => 'Texte', 'es' => 'Texto'],
        ],
    ]);

    Http::fake();

    (new TranslateModelJob($track))->handle();

    Http::assertNothingSent();
});

it('traduce solo i campi effettivamente mancanti per ciascuna lingua, incluso un campo extra', function () {
    $track = EcTrack::factory()->createQuietly([
        'name' => ['it' => 'Nome', 'en' => 'Name', 'de' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'],
        'properties' => [
            'name' => ['it' => 'Nome', 'en' => 'Name', 'de' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'],
            'description' => ['it' => 'Descrizione italiana'],
            'not_accessible_message' => ['it' => 'Non accessibile', 'en' => 'Not accessible'],
        ],
    ]);

    fakeEchoTranslation();

    $job = new TranslateModelJob($track, ['en', 'de', 'fr', 'es'], [
        'not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE,
    ]);
    $job->handle();

    // 4 chiamate: description manca in tutte le lingue, not_accessible_message manca solo in de/fr/es
    Http::assertSentCount(4);

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        if (! str_contains($body['messages'][0]['content'], 'into English')) {
            return true; // non è la richiesta EN, ignora in questa assertion
        }

        $fields = array_keys(json_decode($body['messages'][1]['content'], true));

        return $fields === ['description']; // 'name' e 'not_accessible_message' già presenti in EN
    });

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        if (! str_contains($body['messages'][0]['content'], 'into German')) {
            return true;
        }

        $fields = array_keys(json_decode($body['messages'][1]['content'], true));
        sort($fields);

        return $fields === ['description', 'not_accessible_message'];
    });

    $track->refresh();

    expect($track->properties['description']['en'])->toBe('TRANSLATED:Descrizione italiana');
    expect($track->properties['description']['de'])->toBe('TRANSLATED:Descrizione italiana');
    expect($track->properties['not_accessible_message']['en'])->toBe('Not accessible'); // invariato, era già presente
    expect($track->properties['not_accessible_message']['de'])->toBe('TRANSLATED:Non accessibile');
    expect($track->getTranslation('name', 'en', false))->toBe('Name'); // name non toccato
});

it('costruisce il prompt con la regola dedicata di name e il fallback generico per il campo extra', function () {
    $track = EcTrack::factory()->createQuietly([
        'name' => ['it' => 'Nome'],
        'properties' => [
            'not_accessible_message' => ['it' => 'Non accessibile'],
        ],
    ]);

    fakeEchoTranslation();

    $job = new TranslateModelJob($track, ['en'], [
        'not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE,
    ]);
    $job->handle();

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $prompt = $body['messages'][0]['content'];

        return str_contains($prompt, 'Rules for "name"')
            && str_contains($prompt, 'Keep proper nouns')
            && str_contains($prompt, 'Rules for "not_accessible_message"')
            && str_contains($prompt, 'Translate freely, preserving the meaning and tone')
            && ! str_contains($prompt, 'Rules for "description"'); // description non richiesto in questa chiamata
    });
});

it('scarta un valore che sembra un rifiuto del modello e non lo scrive', function () {
    $track = EcTrack::factory()->createQuietly([
        'name' => ['it' => 'Nome', 'en' => 'Name', 'de' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'],
        'properties' => [
            'name' => ['it' => 'Nome', 'en' => 'Name', 'de' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'],
            'description' => ['it' => 'Testo', 'en' => 'Text', 'de' => 'Text', 'fr' => 'Texte', 'es' => 'Texto'],
            'not_accessible_message' => ['it' => 'Non accessibile'],
        ],
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => json_encode(['not_accessible_message' => "I'm sorry, I cannot translate this."])]],
            ],
        ]),
    ]);

    $job = new TranslateModelJob($track, ['en'], [
        'not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE,
    ]);
    $job->handle();

    $track->refresh();

    expect($track->properties['not_accessible_message']['en'] ?? null)->toBeNull();
});
