<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Validation\ValidationException;
use Wm\WmPackage\Http\Requests\TrailRegistry\PatchApplicationRequest;
use Wm\WmPackage\Http\Requests\TrailRegistry\StoreAttachmentRequest;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Exceptions\MissingDifficultyFamilyException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationField;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

// La validazione dei campi del modulo vive qui, sulla modifica: l'apertura
// della domanda non li accetta piu' (oc:9056). Le Form Request si esercitano
// direttamente, senza chiamata HTTP: le rotte vivono nello shard.

/**
 * Un payload completo e valido.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function completeApplicationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sentiero di prova',
        'from' => 'Punto A',
        'to' => 'Punto B',
        'distance' => 12.5,
        'ascent' => 300,
        'duration_forward' => 240,
        'duration_backward' => 200,
        'activities' => ['hiking', 'mtb'],
        'primary_activity' => 'hiking',
        'difficulty' => ['hiking' => 'EE'],
        'existing_signage' => 'good_condition',
        'managing_subjects' => [['name' => 'Comune di Cagliari', 'email' => 'comune@example.com', 'subject' => 'organization']],
        'surveying_subjects' => [['subject' => 'person']],
        'maintaining_subjects' => [['name' => 'Forestas', 'email' => 'protocollo@forestas.example.it', 'subject' => 'organization']],
        'keeping_subjects' => [],
        'last_survey_date' => '2026-01-15',
        'maintenance_frequency_months' => 12,
    ], $overrides);
}

/**
 * Costruisce la Form Request con il payload dato e la valida come farebbe il
 * container prima di un controller. Lancia ValidationException se fallisce.
 *
 * @param  array<string, mixed>  $payload
 */
function resolveApplicationRequest(array $payload, bool $withGeometry = false, array $query = [], array $extraFiles = [], ?TrailApplication $application = null): PatchApplicationRequest
{
    $files = ($withGeometry ? ['geometry' => UploadedFile::fake()->create('traccia.gpx', 1)] : []) + $extraFiles;

    $request = PatchApplicationRequest::create('/?'.http_build_query($query), 'POST', $payload, [], $files);
    $request->setContainer(app())->setRedirector(app('redirect'));

    // La richiesta legge la domanda dalla rotta: senza chiamata HTTP la si
    // mette a mano, con il payload gia' salvato che l'unione deve vedere.
    $application ??= TrailApplication::factory()->createQuietly(['source' => 'api']);

    $route = new RoutingRoute(['PATCH'], '/{application}', fn () => null);
    $route->bind($request);
    $route->setParameter('application', $application);
    $request->setRouteResolver(fn () => $route);

    $request->validateResolved();

    return $request;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, list<string>>
 */
function applicationErrors(array $payload, bool $withGeometry = true): array
{
    try {
        resolveApplicationRequest($payload, $withGeometry);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

beforeEach(function () {
    // La richiesta legge la domanda dal database: senza le tabelle del
    // dominio non c'e' niente da leggere.
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);

    foreach (['hiking-difficulty' => ['T', 'E', 'EE', 'EEA'], 'mtb-difficulty' => ['TC', 'MC', 'BC']] as $identifier => $levels) {
        $family = TaxonomyTerm::create(['identifier' => $identifier, 'name' => ['it' => $identifier]]);
        foreach ($levels as $level) {
            TaxonomyTerm::create([
                // L'identifier e' `<famiglia>-<valore dell'API>`, come in produzione.
                'identifier' => $identifier.'-'.$level,
                'parent_id' => $family->id,
                'name' => ['it' => $level],
            ]);
        }
    }
});

it('accetta una consegna completa', function () {
    expect(applicationErrors(completeApplicationPayload()))->toBe([]);
});

it('dice campo per campo quale regola ha fallito', function () {
    $errors = applicationErrors(completeApplicationPayload([
        'managing_subjects' => [['name' => 'Comune di Cagliari', 'email' => 'non-una-email', 'subject' => 'organization']],
    ]));

    expect($errors)->toHaveKey('managing_subjects.0.email');
    expect($errors['managing_subjects.0.email'][0])->toContain('email');
    expect($errors)->toHaveCount(1);
});

it('non pretende piu i campi obbligatori dello schema: la domanda si completa dopo', function () {
    // La domanda nasce con il minimo e si riempie in piu' riprese, quindi un
    // campo obbligatorio che non e' ancora stato compilato non e' un errore
    // qui. L'obbligatorieta' dichiarata dallo schema vale alla conferma.
    $payload = completeApplicationPayload();
    unset($payload['from'], $payload['last_survey_date']);

    expect(applicationErrors($payload))->not->toHaveKey('from')
        ->and(applicationErrors($payload))->not->toHaveKey('last_survey_date');
});

it('un campo obbligatorio mandato vuoto resta un errore', function () {
    // `sometimes` dice «se non lo mandi va bene»; mandarlo senza valore e'
    // un'altra cosa, ed e' sempre un errore di forma.
    expect(applicationErrors(completeApplicationPayload(['from' => ''])))
        ->toHaveKey('from');
});

it('chiede la e-mail a un ente e non a una persona', function () {
    // Il wildcard risolve l'indice fratello: ogni soggetto si valida con il
    // proprio `subject`, non con quello di un altro della stessa lista.
    $errors = applicationErrors(completeApplicationPayload([
        'managing_subjects' => [
            ['subject' => 'person'],
            ['name' => 'Comune di Oliena', 'email' => null, 'subject' => 'organization'],
        ],
    ]));

    expect($errors)->toHaveKey('managing_subjects.1.email')
        ->and($errors)->not->toHaveKey('managing_subjects.0.email');
});

it('rifiuta una lunghezza implausibile, non solo un valore non numerico', function () {
    expect(applicationErrors(completeApplicationPayload(['distance' => 12000])))->toHaveKey('distance');
    expect(applicationErrors(completeApplicationPayload(['distance' => 'lunga'])))->toHaveKey('distance');
    expect(applicationErrors(completeApplicationPayload(['duration_forward' => 999999])))->toHaveKey('duration_forward');
});

it('rifiuta una chiave che il format non prevede, invece di conservarla', function () {
    // Fino a oc:9039 il canale conservava in `api_data` qualunque chiave, per
    // non perdere un campo che il format avesse aggiunto prima di noi. La
    // scelta si e' rovesciata: con un mittente solo e un contratto scritto, un
    // campo non concordato e' quasi sempre un refuso, e va detto a chi integra.
    expect(applicationErrors(completeApplicationPayload(['campo_nuovo_del_format' => 'x'])))
        ->toHaveKey('campo_nuovo_del_format');
});

it('rifiuta una difficolta dichiarata per un attivita non indicata, dicendo quale', function () {
    $errors = applicationErrors(completeApplicationPayload([
        'activities' => ['hiking'],
        'difficulty' => ['hiking' => 'EE', 'mtb' => 'BC'],
    ]));

    expect($errors)->toHaveKey('difficulty.mtb');
    expect($errors['difficulty.mtb'][0])->toContain('mtb');
    expect($errors)->not->toHaveKey('difficulty.hiking');
});

it('accetta una difficolta solo per alcune delle attivita indicate', function () {
    expect(applicationErrors(completeApplicationPayload([
        'activities' => ['hiking', 'mtb'],
        'difficulty' => ['mtb' => 'BC'],
    ])))->toBe([]);
});

it('rifiuta un livello che non appartiene alla scala dell attivita', function () {
    expect(applicationErrors(completeApplicationPayload(['difficulty' => ['mtb' => 'EEA']])))
        ->toHaveKey('difficulty');
});

it('non maschera la mancanza della scala di difficolta: l eccezione risale', function () {
    // Prima i livelli, poi la famiglia: `taxonomy_terms` ha una chiave esterna da figlio a padre
    // senza cascata, quindi cancellare la famiglia da sola solleva una violazione di vincolo e il
    // test verificava quella invece dell'eccezione del dominio.
    $family = TaxonomyTerm::query()->where('identifier', 'mtb-difficulty')->firstOrFail();
    TaxonomyTerm::query()->where('parent_id', $family->id)->delete();
    $family->delete();

    resolveApplicationRequest(completeApplicationPayload([
        'activities' => ['mtb'],
        'difficulty' => ['mtb' => 'BC'],
    ]));
})->throws(MissingDifficultyFamilyException::class);

it('accetta le cinque collection ammesse e un file', function () {
    expect(StoreAttachmentRequest::collections())->toHaveCount(5);

    foreach (StoreAttachmentRequest::collections() as $collection) {
        $validator = Validator::make(
            ['collection' => $collection, 'file' => UploadedFile::fake()->create('allegato.pdf', 1)],
            (new StoreAttachmentRequest)->rules()
        );

        expect($validator->passes())->toBeTrue($collection);
    }
});

it('rifiuta una collection non registrata o un file mancante', function () {
    $rules = (new StoreAttachmentRequest)->rules();

    $unknown = Validator::make(['collection' => 'altro', 'file' => UploadedFile::fake()->create('a.pdf', 1)], $rules);
    expect($unknown->errors()->keys())->toBe(['collection']);

    $noFile = Validator::make(['collection' => TrailApplication::PHOTOS_COLLECTION], $rules);
    expect($noFile->errors()->keys())->toBe(['file']);
});

it('esclude original_geometry dalle collection dell upload', function () {
    expect(StoreAttachmentRequest::collections())->not->toContain(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION);

    $validator = Validator::make(
        ['collection' => TrailApplication::ORIGINAL_GEOMETRY_COLLECTION, 'file' => UploadedFile::fake()->create('a.gpx', 1)],
        (new StoreAttachmentRequest)->rules()
    );

    expect($validator->errors()->keys())->toBe(['collection']);
});

it('decodifica i campi strutturati che arrivano come stringhe in multipart', function () {
    $payload = completeApplicationPayload([
        'activities' => '["hiking","mtb"]',
        'difficulty' => '{"hiking":"EE"}',
        'managing_subjects' => '[{"name":"Comune di Cagliari","email":"comune@example.com","subject":"organization"}]',
    ]);

    expect(applicationErrors($payload))->toBe([]);

    $apiData = resolveApplicationRequest($payload)->bodyFields();

    expect($apiData['activities'])->toBe(['hiking', 'mtb']);
    expect($apiData['difficulty'])->toBe(['hiking' => 'EE']);
    expect($apiData['managing_subjects'][0]['name'])->toBe('Comune di Cagliari');
});

it('converte i booleani che arrivano come stringhe in multipart', function () {
    // Dopo oc:9039 lo schema di questo package non ha piu' campi `boolean`,
    // ma la conversione resta nel codice e uno shard puo' dichiararne uno:
    // lo schema si ridefinisce nel container, come fa uno shard vero.
    app()->bind(ApplicationSchema::class, fn () => new class extends ApplicationSchema
    {
        public function fields(): array
        {
            return [new ApplicationField('flag', 'boolean', 'Flag', null, true, ['required', 'boolean'], null)];
        }
    });

    expect(resolveApplicationRequest(['name' => 'x', 'flag' => 'false'])->bodyFields()['flag'])->toBeFalse();
    expect(resolveApplicationRequest(['name' => 'x', 'flag' => '1'])->bodyFields()['flag'])->toBeTrue();
    expect(resolveApplicationRequest(['name' => 'x', 'flag' => '0'])->bodyFields()['flag'])->toBeFalse();

    // La stringa vuota e' un campo non compilato, non un `false`.
    expect(applicationErrors(['name' => 'x', 'flag' => '']))->toHaveKey('flag');
});

it('accetta la forma nativa dei form per le liste', function () {
    $apiData = resolveApplicationRequest(completeApplicationPayload(['activities' => ['hiking']]))->bodyFields();

    expect($apiData['activities'])->toBe(['hiking'])
        ->and($apiData['managing_subjects'][0]['subject'])->toBe('organization');
});

it('con difficulty presente chiede activities se manca o e vuoto, senza crash', function () {
    $missing = completeApplicationPayload(['difficulty' => ['hiking' => 'EE']]);
    unset($missing['activities']);
    expect(applicationErrors($missing))->toHaveKey('activities');

    expect(applicationErrors(completeApplicationPayload(['activities' => [], 'difficulty' => ['hiking' => 'EE']])))
        ->toHaveKey('activities');
});

it('non conserva in api_data ne i file extra ne la query string', function () {
    // Le chiavi ignote del corpo non arrivano piu' fin qui: dopo oc:9039 la
    // validazione le rifiuta. Query string e file sotto chiavi non previste
    // non fanno fallire la chiamata — non sono il contratto dei campi — ma
    // restano fuori dal verbale.
    $apiData = resolveApplicationRequest(
        completeApplicationPayload(),
        query: ['_token' => 'abc', 'altro' => '1'],
        extraFiles: ['allegato_strano' => UploadedFile::fake()->create('x.pdf', 1)],
    )->bodyFields();

    expect($apiData)->toHaveKey('from')
        ->and($apiData)->not->toHaveKeys(['_token', 'altro', 'allegato_strano', 'geometry', 'name']);
});

it('trasforma un JSON malformato in un errore di validazione, non in un crash', function () {
    $errors = applicationErrors(completeApplicationPayload([
        'activities' => '["hiking",',
        'difficulty' => '{non json',
    ]));

    expect($errors)->toHaveKeys(['activities', 'difficulty']);
    expect($errors['activities'][0])->toContain('array');
});

it('decodifica una lista di soggetti arrivata come JSON in stringa', function () {
    $payload = completeApplicationPayload([
        'maintaining_subjects' => '[{"name":"Ente A","email":"a@example.it","subject":"organization"},{"subject":"person"}]',
    ]);

    expect(applicationErrors($payload))->toBe([]);
    expect(resolveApplicationRequest($payload)->bodyFields()['maintaining_subjects'])->toBe([
        ['name' => 'Ente A', 'email' => 'a@example.it', 'subject' => 'organization'],
        ['subject' => 'person'],
    ]);
});

it('rifiuta una primaria che non e fra le attivita dichiarate', function () {
    $errors = applicationErrors(completeApplicationPayload([
        'activities' => ['hiking', 'mtb'],
        'difficulty' => null,
        'primary_activity' => 'horse',
    ]));

    expect($errors)->toHaveKey('primary_activity');
});

it('accetta una primaria che e fra le attivita dichiarate', function () {
    $errors = applicationErrors(completeApplicationPayload([
        'activities' => ['hiking', 'mtb'],
        'primary_activity' => 'hiking',
    ]));

    expect($errors)->not->toHaveKey('primary_activity');
});

it('non aggiunge l errore incrociato quando le attivita sono gia invalide', function () {
    $errors = applicationErrors(completeApplicationPayload([
        'activities' => 'non un array',
        'primary_activity' => 'hiking',
    ]));

    expect($errors)->toHaveKey('activities');
    expect($errors)->not->toHaveKey('primary_activity');
});

it('non aggiunge l errore incrociato quando la primaria e gia invalida', function () {
    $errors = applicationErrors(completeApplicationPayload(['primary_activity' => 'trail']));

    expect($errors['primary_activity'])->toHaveCount(1);
    expect($errors['primary_activity'][0])->not->toContain('not among the proposed');
});

it('esige la primaria', function () {
    // Da quando i campi del modulo non si pretendono piu' alla consegna,
    // l'errore non viene dal `required` dello schema ma dal legame fra i tre
    // campi: chi manda le attivita' manda anche la primaria.
    $payload = completeApplicationPayload();
    unset($payload['primary_activity']);

    expect(applicationErrors($payload))->toHaveKey('primary_activity');
});

it('non esige la primaria se non arrivano nemmeno le attivita', function () {
    $payload = completeApplicationPayload();
    unset($payload['primary_activity'], $payload['activities'], $payload['difficulty']);

    // Nessuno dei tre campi legati e' in viaggio: non c'e' niente da tenere
    // coerente, e li pretendera' la conferma.
    expect(applicationErrors($payload))->toBe([]);
});

it('rifiuta una lista di stringhe al posto di oggetti', function () {
    // E' la forma che aveva il campo 54 (`soggetto_manutentore_other`): la
    // migrazione sbagliata piu' probabile da parte di chi integra.
    expect(applicationErrors(completeApplicationPayload(['managing_subjects' => ['Comune di Oliena']])))
        ->toHaveKey('managing_subjects.0');
});

it('rifiuta un oggetto singolo al posto della lista', function () {
    // Le chiavi d'errore sono illeggibili (`managing_subjects.name.name`), ma
    // il rifiuto c'e': la regola `array` sull'elemento vede le tre chiavi
    // dell'oggetto come tre elementi scalari.
    expect(applicationErrors(completeApplicationPayload([
        'managing_subjects' => ['name' => 'X', 'email' => 'x@y.it', 'subject' => 'organization'],
    ])))->toHaveKey('managing_subjects.name');
});

it('accetta un soggetto persona fisica senza nome ne email', function () {
    // E' la forma che la pagina delle API pubblica nell'esempio `curl` e nel
    // modulo del bottone di prova: `{"subject":"person"}` e basta. Finche'
    // nessun test la fissa, un `required` aggiunto per distrazione renderebbe
    // sbagliato l'esempio pubblicato.
    expect(applicationErrors(completeApplicationPayload([
        'surveying_subjects' => [['subject' => 'person']],
    ])))->toBe([]);

    // Un ente senza la chiave, invece, viene rifiutato come se fosse `null`.
    expect(applicationErrors(completeApplicationPayload([
        'managing_subjects' => [['name' => 'Comune di Oliena', 'subject' => 'organization']],
    ])))->toHaveKey('managing_subjects.0.email');
});

// I quattro ruoli hanno regole identiche, e proprio per questo il ciclo serve:
// finche' il nome era obbligatorio ovunque, una regola dimenticata su un ruolo
// non faceva fallire nessun test (oc:9061).
it('accetta un privato senza nome ne e-mail su tutti e quattro i ruoli', function () {
    foreach (['managing_subjects', 'surveying_subjects', 'maintaining_subjects', 'keeping_subjects'] as $role) {
        expect(applicationErrors(completeApplicationPayload([$role => [['subject' => 'person']]])))
            ->toBe([], "il ruolo {$role} ha rifiutato un privato senza dati personali");
    }
});

// `null` e la stringa vuota non sono un dato personale: valgono come l'assenza
// del campo, ed e' la scorciatoia naturale di un form che ha quel campo
// obbligatorio dalla propria parte.
it('accetta un privato con nome null o stringa vuota', function () {
    expect(applicationErrors(completeApplicationPayload([
        'surveying_subjects' => [['name' => null, 'email' => null, 'subject' => 'person']],
    ])))->toBe([])
        ->and(applicationErrors(completeApplicationPayload([
            'surveying_subjects' => [['name' => '', 'email' => '', 'subject' => 'person']],
        ])))->toBe([]);
});

it('rifiuta il nome di un privato, segnaposto compreso', function () {
    expect(applicationErrors(completeApplicationPayload([
        'surveying_subjects' => [['name' => 'Mario Rossi', 'subject' => 'person']],
    ])))->toHaveKey('surveying_subjects.0.name');

    // In call era stato proposto un valore convenzionale al posto del dato
    // vero: per il canale e' un valore come un altro.
    expect(applicationErrors(completeApplicationPayload([
        'surveying_subjects' => [['name' => 'campo oscurato per motivi di privacy', 'subject' => 'person']],
    ])))->toHaveKey('surveying_subjects.0.name');
});

// Chi legge questo errore e' chi integra, e in call aveva chiesto l'opposto:
// un segnaposto al posto del dato. Il messaggio di default direbbe solo che il
// campo non e' ammesso quando `subject` e' `person`, senza dire perche' ne'
// dove recuperare il dato vero (oc:9061).
it('spiega perche il dato di un privato non e ammesso', function () {
    $errori = applicationErrors(completeApplicationPayload([
        'surveying_subjects' => [['name' => 'Mario Rossi', 'subject' => 'person']],
    ]));

    // Confrontato con la voce tradotta e non con il testo italiano: la suite
    // gira in inglese, e il punto e' che esca il messaggio dello schema invece
    // di quello di default di `prohibited_if`.
    expect($errori['surveying_subjects.0.name'][0])
        ->toBe(__('The name and e-mail address of a person subject are not transmitted to the Catasto: omit the field and retrieve the data from the SUS using the application reference.'));
});

it('rifiuta la e-mail di un privato', function () {
    expect(applicationErrors(completeApplicationPayload([
        'surveying_subjects' => [['email' => 'mario.rossi@example.it', 'subject' => 'person']],
    ])))->toHaveKey('surveying_subjects.0.email');
});

it('pretende nome ed e-mail dall ente', function () {
    expect(applicationErrors(completeApplicationPayload([
        'managing_subjects' => [['subject' => 'organization']],
    ])))->toHaveKeys(['managing_subjects.0.name', 'managing_subjects.0.email']);
});

// Senza `subject` non si sa di che natura sia il soggetto, quindi ne' l'obbligo
// ne' il divieto scattano: l'unico errore e' quello che spiega cosa manca
// davvero.
it('segnala solo subject quando il soggetto non dichiara la propria natura', function () {
    $errori = applicationErrors(completeApplicationPayload([
        'surveying_subjects' => [['name' => 'Mario Rossi']],
    ]));

    expect(array_keys($errori))->toBe(['surveying_subjects.0.subject']);
});

it('accetta un istanza senza alcun custode', function () {
    $senza = completeApplicationPayload();
    unset($senza['keeping_subjects']);
    expect(applicationErrors($senza))->toBe([]);

    expect(applicationErrors(completeApplicationPayload(['keeping_subjects' => []])))->toBe([]);
});

it('rifiuta un ruolo obbligatorio con la lista vuota', function () {
    expect(applicationErrors(completeApplicationPayload(['managing_subjects' => []])))
        ->toHaveKey('managing_subjects');
});

it('ma non dire niente di un ruolo obbligatorio e lecito: si compila dopo', function () {
    // La differenza che conta: una lista mandata vuota e' una dichiarazione
    // sbagliata, una lista non mandata e' un campo non ancora compilato.
    $payload = completeApplicationPayload();
    unset($payload['managing_subjects']);

    expect(applicationErrors($payload))->toBe([]);
});

it('dice nell errore quale ruolo e quale posizione, contando da zero', function () {
    // Il comportamento viene dall'espansione del wildcard, non da
    // `attributes()`: il test guarda il TESTO, perche' una voce in
    // `validation.attributes` lo romperebbe senza cambiare la chiave.
    $errors = applicationErrors(completeApplicationPayload([
        'surveying_subjects' => [
            ['name' => 'A', 'email' => 'a@b.it', 'subject' => 'organization'],
            ['name' => 'B', 'email' => 'non-una-email', 'subject' => 'organization'],
        ],
    ]));

    expect($errors['surveying_subjects.1.email'][0])->toContain('surveying_subjects.1.email');
});

it('rifiuta una chiave del payload che lo schema non dichiara, nominandola', function () {
    // Il SUS e' un mittente solo, con un contratto scritto: una chiave che non
    // abbiamo concordato e' quasi sempre un refuso, e ignorarla la farebbe
    // sparire in silenzio dentro `api_data`. Meglio dirglielo adesso, mentre
    // integra, che lasciarglielo scoprire fra sei mesi (oc:9039).
    $errori = applicationErrors(completeApplicationPayload(['campo_inventato' => 'x']));

    expect($errori)->toHaveKey('campo_inventato')
        ->and($errori['campo_inventato'][0])->toContain('campo_inventato');
});

it('rifiuta i tredici campi della forma vecchia, uno per uno', function () {
    // Engineering ha integrato la forma precedente: se continuasse a mandarla
    // deve leggere quale campo non esiste piu', non ricevere un 201 muto.
    $errori = applicationErrors(completeApplicationPayload([
        'soggetto_gestore' => 'forestas',
        'municipality_email' => 'comune@example.com',
    ]));

    expect($errori)->toHaveKeys(['soggetto_gestore', 'municipality_email']);
});

it('rifiuta una chiave non concordata dentro un soggetto, nominandola', function () {
    $errori = applicationErrors(completeApplicationPayload([
        'managing_subjects' => [['name' => 'X', 'email' => 'a@b.it', 'subject' => 'organization', 'cf' => 'RSSMRA80A01H501U']],
    ]));

    expect($errori)->toHaveKey('managing_subjects.0.cf')
        ->and($errori['managing_subjects.0.cf'][0])->toContain('cf');
});

it('dice quali sono i valori concordati quando ne arriva un altro', function () {
    $errori = applicationErrors(completeApplicationPayload([
        'managing_subjects' => [['name' => 'X', 'email' => 'a@b.it', 'subject' => 'ente']],
    ]));

    expect($errori['managing_subjects.0.subject'][0])
        ->toContain('organization')
        ->toContain('person');
});
