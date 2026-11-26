<?php

use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationField;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

function applicationSchema(): ApplicationSchema
{
    return app(ApplicationSchema::class);
}

it('espone i 19 campi del format nell ordine della numerazione', function () {
    expect(applicationSchema()->fields())->toHaveCount(19);
    expect(applicationSchema()->fields()[0]->name)->toBe('from');
    expect(applicationSchema()->fields()[18]->name)->toBe('maintenance_frequency_months');
});

it('non mette fra gli obbligatori cio che il format non chiede sempre', function () {
    // `difficulty`: il format ne valida la forma ma non ne chiede la presenza.
    // `keeping_subjects`: il custode e' facoltativo.
    expect(applicationSchema()->requiredFieldNames())
        ->not->toContain('keeping_subjects')
        ->not->toContain('difficulty');
});

it('ha come obbligatori i tredici campi che il format dichiara tali', function () {
    expect(applicationSchema()->requiredFieldNames())->toHaveCount(13)
        ->toContain('from', 'to', 'distance', 'managing_subjects', 'maintenance_frequency_months');
});

it('esige la attivita primaria', function () {
    expect(applicationSchema()->requiredFieldNames())->toContain('primary_activity');
});

it('ammette solo le sette attivita come primaria', function () {
    $rules = applicationSchema()->rules()['primary_activity'];

    expect(Validator::make(['primary_activity' => 'trail'], ['primary_activity' => $rules])->passes())->toBeFalse();
    expect(Validator::make(['primary_activity' => 'hiking'], ['primary_activity' => $rules])->passes())->toBeTrue();
});

it('esprime le dipendenze fra campi come regole Laravel native', function () {
    // Dopo oc:9039 l'unica dipendenza fra campi vive dentro le liste di
    // soggetti: la e-mail e' obbligatoria solo per un ente. Il wildcard
    // risolve l'indice fratello, quindi ogni soggetto si valida con il
    // proprio `subject` e non con quello di un altro.
    expect(applicationSchema()->rules()['keeping_subjects.*.email'])
        ->toContain('required_if:keeping_subjects.*.subject,organization');
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

it('da un etichetta a ogni valore dei campi a scelta, con la dicitura del format', function () {
    // L'etichetta serve a chi integra per ritrovare il valore fra le voci del
    // format, e all'operatore in Nova per non leggere un codice grezzo.
    $attese = [
        'existing_signage' => ['good_condition' => "c'è ed è in buono stato", 'dilapidated' => "c'è ed è fatiscente", 'absent' => "non c'è"],
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

        // `primary_activity` ammette le sette attivita' di `ACTIVITIES`, che
        // la documentazione descrive a parte: scriverne l'elenco anche qui
        // andrebbe tenuto allineato a mano.
        if ($field->name === 'primary_activity') {
            expect($field->values)->toBeNull();

            continue;
        }

        $ammessi = explode(',', substr($regola, 3));
        $etichettati = collect($field->values ?? [])->pluck('value')->all();

        expect($etichettati)->toBe($ammessi, $field->name);
    }
});

it('scrive per la documentazione ogni valore accanto alla sua etichetta', function () {
    app()->setLocale('it');

    expect(applicationSchema()->valuesDescription('existing_signage'))
        ->toBe("Valori ammessi: `good_condition` — *c'è ed è in buono stato*; `dilapidated` — *c'è ed è fatiscente*; `absent` — *non c'è*.");
});

it('usa l etichetta della lingua corrente e ripiega sull italiano', function () {
    app()->setLocale('en');
    expect(applicationSchema()->valuesDescription('existing_signage'))->toContain('*present and in good condition*');

    // Il tedesco non ha etichette proprie: vale quella del format.
    app()->setLocale('de');
    expect(applicationSchema()->valuesDescription('existing_signage'))->toContain("*c'è ed è in buono stato*");
});

it('non scrive nulla per un campo senza elenco di valori', function () {
    expect(applicationSchema()->valuesDescription('from'))->toBeNull()
        ->and(applicationSchema()->valuesDescription('campo_inesistente'))->toBeNull();
});

it('dichiara le quattro liste di soggetti e non piu le select di categoria', function () {
    $names = array_column(applicationSchema()->fields(), 'name');

    expect($names)->toContain('managing_subjects', 'surveying_subjects', 'maintaining_subjects', 'keeping_subjects')
        ->and($names)->not->toContain(
            'proposed_managing_municipalities', 'municipality_email', 'municipality_email_alt',
            'soggetto_gestore', 'soggetto_gestore_other',
            'soggetto_rilevatore', 'soggetto_rilevatore_other', 'soggetto_rilevatore_email',
            'soggetto_manutentore', 'soggetto_manutentore_other', 'soggetto_manutentore_cf',
            'soggetto_custode_presente', 'soggetto_custode',
        );
});

it('chiede almeno un soggetto per i tre ruoli obbligatori e nessuno per il custode', function () {
    $required = applicationSchema()->requiredFieldNames();

    expect($required)->toContain('managing_subjects', 'surveying_subjects', 'maintaining_subjects')
        ->and($required)->not->toContain('keeping_subjects');
});

// Nome ed e-mail sono simmetrici e valgono allo stesso modo sui quattro ruoli:
// l'ente li deve, il privato non li puo' mandare. Il ciclo sui ruoli non e' un
// vezzo — finche' il nome era `required` ovunque, dimenticarne uno non faceva
// fallire niente (oc:9061).
it('vieta i dati personali del soggetto privato e li pretende dall ente', function () {
    $rules = applicationSchema()->rules();

    foreach (['managing_subjects', 'surveying_subjects', 'maintaining_subjects', 'keeping_subjects'] as $role) {
        expect($rules["{$role}.*.name"])->toContain(
            'nullable', 'string', 'max:255',
            "required_if:{$role}.*.subject,organization",
            "prohibited_if:{$role}.*.subject,person",
        )
            ->and($rules["{$role}.*.email"])->toContain(
                'nullable', 'email',
                "required_if:{$role}.*.subject,organization",
                "prohibited_if:{$role}.*.subject,person",
            )
            ->and($rules["{$role}.*.subject"])->toContain('required', 'in:organization,person')
            ->and($rules["{$role}.*"])->toContain('array');
    }
});

// La versione si alza quando il contratto e' in uso e un payload accettato con
// regole diverse va distinto da uno nuovo. Finche' il canale si costruisce non
// distingue niente: era salita a 2 con oc:9038 e a 3 con oc:9039, contando i
// ticket (oc:9061).
it('tiene ferma la versione dello schema finche il canale e in costruzione', function () {
    expect(applicationSchema()->version())->toBe('1');
});

it('ammette solo i dieci tematismi validati', function () {
    $regola = applicationSchema()->rules()['themes.*'][1];

    expect($regola)->toContain(
        'history-archaeology', 'mining-geology', 'transhumance', 'spirituality', 'woods-forests',
        'flora-fauna', 'waters', 'sea-coast', 'villages', 'rural-landscape',
    );

    expect(Validator::make(['themes' => ['spiritualita']], ['themes.*' => applicationSchema()->rules()['themes.*']])->passes())->toBeFalse();
    expect(Validator::make(['themes' => ['spirituality', 'waters']], ['themes.*' => applicationSchema()->rules()['themes.*']])->passes())->toBeTrue();
});

it('mette un tetto alla lunghezza di ogni campo di testo', function () {
    // Senza un tetto il canale accetta una stringa da megabyte e la deposita
    // nel verbale. Il format non dichiara limiti per questi campi: 255 è lo
    // stesso che il nome del sentiero ha già, 600 l'unico limite che il format
    // nomina (campo 45, prima che diventasse una lista di identifier).
    $rules = applicationSchema()->rules();

    foreach (['from', 'to', 'intermediate_stop'] as $campo) {
        expect($rules[$campo])->toContain('max:255');
    }

    expect($rules['overlapping_routes'])->toContain('max:600')
        ->and($rules['managing_subjects.*.name'])->toContain('max:255');
});
