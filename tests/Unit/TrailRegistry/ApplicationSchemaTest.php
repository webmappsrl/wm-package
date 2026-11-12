<?php

use Wm\WmPackage\TrailRegistry\Schema\ApplicationField;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

function applicationSchema(): ApplicationSchema
{
    return app(ApplicationSchema::class);
}

it('espone i 27 campi del format nell ordine della numerazione', function () {
    expect(applicationSchema()->fields())->toHaveCount(27);
    expect(applicationSchema()->fields()[0]->name)->toBe('from');
    expect(applicationSchema()->fields()[26]->name)->toBe('maintenance_frequency_months');
});

it('non mette fra gli obbligatori i campi la cui obbligatorieta dipende da un altro campo', function () {
    expect(applicationSchema()->requiredFieldNames())
        ->not->toContain('soggetto_gestore_other')
        ->not->toContain('soggetto_rilevatore_other')
        ->not->toContain('soggetto_rilevatore_email')
        ->not->toContain('soggetto_manutentore_other')
        ->not->toContain('soggetto_manutentore_cf')
        ->not->toContain('soggetto_custode')
        ->not->toContain('difficulty');
});

it('ha come obbligatori i quindici campi che il format dichiara tali', function () {
    expect(applicationSchema()->requiredFieldNames())->toHaveCount(15)
        ->toContain('from', 'to', 'distance', 'soggetto_custode_presente', 'maintenance_frequency_months');
});

it('esprime le dipendenze fra campi come regole Laravel native', function () {
    expect(applicationSchema()->rules()['soggetto_gestore_other'])
        ->toContain('required_if:soggetto_gestore,third_party');
    expect(applicationSchema()->rules()['soggetto_custode'])
        ->toContain('required_if:soggetto_custode_presente,true');
});

it('valida le attivita elemento per elemento sui sette identificatori', function () {
    expect(applicationSchema()->rules()['activities.*'][1])->toContain('hiking', 'educational-trails');
});

it('valida last_survey_date come data non futura ma la mostra come testo', function () {
    $field = collect(applicationSchema()->fields())->firstWhere('name', 'last_survey_date');

    expect($field->rules)->toContain('date')->toContain('before_or_equal:today');
    expect($field->toArray()['type'])->toBe('text');
});

it('si lascia ridefinire dallo shard', function () {
    // lo shard registra la propria implementazione nel container
    app()->bind(ApplicationSchema::class, fn () => new class extends ApplicationSchema
    {
        public function fields(): array
        {
            return [new ApplicationField('solo_questo', 'text', 'Solo questo', null, true, ['string'], null)];
        }
    });

    expect(app(ApplicationSchema::class)->requiredFieldNames())->toBe(['solo_questo']);
    expect(app(ApplicationSchema::class)->toNovaSchema())->toHaveCount(1);
});

it('da a Nova la stessa forma che la Resource aveva prima', function () {
    $schema = collect(applicationSchema()->toNovaSchema());
    $signage = $schema->firstWhere('name', 'existing_signage');

    expect($signage['type'])->toBe('select');
    expect(collect($signage['values'])->pluck('value')->all())
        ->toBe(['good_condition', 'dilapidated', 'absent']);
    expect($schema->firstWhere('name', 'from'))->toBe(['name' => 'from', 'type' => 'text', 'label' => __('Start point')]);
});

it('espone la versione dello schema', function () {
    expect(applicationSchema()->version())->toBeString()->not->toBeEmpty();
});

it('dichiara di tipo json ogni campo la cui regola e array', function () {
    foreach (applicationSchema()->fields() as $field) {
        if (in_array('array', $field->rules, true)) {
            expect($field->type)->toBe('json', $field->name);
        }
    }
});

it('mostra come text il campo 54, che si valida come array di stringhe', function () {
    $field = collect(applicationSchema()->fields())->firstWhere('name', 'soggetto_manutentore_other');

    expect($field->type)->toBe('json');
    expect($field->toArray()['type'])->toBe('text');
});

it('da un etichetta a ogni valore dei campi a scelta, con la dicitura del format', function () {
    // L'etichetta serve a chi integra per ritrovare il valore fra le voci del
    // format, e all'operatore in Nova per non leggere un codice grezzo.
    $attese = [
        'existing_signage' => ['good_condition' => "c'è ed è in buono stato", 'dilapidated' => "c'è ed è fatiscente", 'absent' => "non c'è"],
        'soggetto_gestore' => ['applicant' => 'Il richiedente', 'municipalities' => 'Comuni territorialmente competenti selezionati', 'third_party' => 'Altro soggetto terzo', 'forestas' => 'Forestas'],
        'soggetto_rilevatore' => ['applicant' => 'Il richiedente', 'other_surveyor' => 'Altro soggetto rilevatore'],
        'soggetto_manutentore' => ['applicant' => 'Il richiedente (se soggetto giuridico)', 'municipalities' => 'Comuni territorialmente competenti selezionati', 'other_legal_entity' => 'Altro soggetto giuridico', 'forestas' => 'Forestas'],
    ];

    foreach ($attese as $nome => $etichette) {
        $field = collect(applicationSchema()->fields())->firstWhere('name', $nome);
        $trovate = collect($field->values)->mapWithKeys(fn ($o) => [$o['value'] => $o['label']['it']])->all();

        expect($trovate)->toBe($etichette, $nome);
    }
});

it('ha un etichetta per ogni valore che la regola ammette, e nessuna in piu', function () {
    // La regola `in:` e l'elenco delle etichette sono due scritture dello
    // stesso fatto: se divergono, un valore valido resta senza etichetta, o
    // un'etichetta descrive un valore che la validazione rifiuta.
    foreach (applicationSchema()->fields() as $field) {
        $regola = collect($field->rules)->first(fn ($r) => is_string($r) && str_starts_with($r, 'in:'));

        if ($regola === null) {
            continue;
        }

        $ammessi = explode(',', substr($regola, 3));
        $etichettati = collect($field->values ?? [])->pluck('value')->all();

        expect($etichettati)->toBe($ammessi, $field->name);
    }
});

it('scrive per la documentazione ogni valore accanto alla sua etichetta', function () {
    app()->setLocale('it');

    expect(applicationSchema()->valuesDescription('soggetto_rilevatore'))
        ->toBe('Valori ammessi: `applicant` — *Il richiedente*; `other_surveyor` — *Altro soggetto rilevatore*.');
});

it('usa l etichetta della lingua corrente e ripiega sull italiano', function () {
    app()->setLocale('en');
    expect(applicationSchema()->valuesDescription('soggetto_rilevatore'))->toContain('*The applicant*');

    // Il tedesco non ha etichette proprie: vale quella del format.
    app()->setLocale('de');
    expect(applicationSchema()->valuesDescription('soggetto_rilevatore'))->toContain('*Il richiedente*');
});

it('non scrive nulla per un campo senza elenco di valori', function () {
    expect(applicationSchema()->valuesDescription('from'))->toBeNull()
        ->and(applicationSchema()->valuesDescription('campo_inesistente'))->toBeNull();
});
