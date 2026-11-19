<?php

use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Exceptions\MissingDifficultyFamilyException;
use Wm\WmPackage\TrailRegistry\Rules\DifficultyMatchesActivities;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * Crea una famiglia di difficolta' con i suoi livelli, come mappa
 * `etichetta italiana => valore dell'API`.
 *
 * L'identifier del termine e' `<famiglia>-<valore>`: il suffisso e' cio' che il SUS manda. Il
 * nome e' l'etichetta che si legge in Nova, e nel test e' diverso dal valore ovunque si possa,
 * perche' la Rule non deve guardarlo.
 *
 * @param  array<string, string>  $levels
 */
function seedDifficultyFamily(string $identifier, array $levels): void
{
    $family = TaxonomyTerm::create([
        'identifier' => $identifier,
        'name' => ['it' => $identifier],
    ]);

    foreach ($levels as $name => $value) {
        TaxonomyTerm::create([
            'identifier' => $identifier.'-'.$value,
            'parent_id' => $family->id,
            'name' => ['it' => $name],
        ]);
    }
}

function validateDifficulty(mixed $difficulty): bool
{
    return Validator::make(['difficulty' => $difficulty], ['difficulty' => [new DifficultyMatchesActivities]])->passes();
}

beforeEach(function () {
    seedDifficultyFamily('hiking-difficulty', ['T' => 'T', 'E' => 'E', 'EE' => 'EE', 'EEA' => 'EEA']);
    seedDifficultyFamily('mtb-difficulty', ['TC' => 'TC', 'MC' => 'MC', 'BC' => 'BC', 'OC' => 'OC', 'EC' => 'EC']);
    seedDifficultyFamily('inclusivity-difficulty', ['AT' => 'AT', 'AE' => 'AE', 'AEE' => 'AEE']);
    seedDifficultyFamily('soft-mobility-difficulty', ['Facile' => 'easy', 'Medio' => 'medium']);
    seedDifficultyFamily('horse-difficulty', ['Facile' => 'easy', 'Medio' => 'medium', 'Difficile' => 'hard']);
    seedDifficultyFamily('nordic-walking-difficulty', ['Facile' => 'easy', 'Medio' => 'medium', 'Impegnativo' => 'hard']);
    seedDifficultyFamily('educational-trails-difficulty', ['Facile' => 'easy', 'Medio' => 'medium', 'Impegnativo' => 'hard']);
});

it('accetta un livello che appartiene alla scala dell attivita', function () {
    expect(validateDifficulty(['hiking' => 'EE']))->toBeTrue();
});

it('rifiuta un livello che esiste ma appartiene a un altra scala', function () {
    expect(validateDifficulty(['mtb' => 'EEA']))->toBeFalse();
});

it('rifiuta un attivita che non e fra le sette', function () {
    expect(validateDifficulty(['sci-alpinismo' => 'EE']))->toBeFalse();
});

it('accetta una mappa con piu attivita se ogni livello e della sua scala', function () {
    expect(validateDifficulty(['hiking' => 'EE', 'mtb' => 'BC']))->toBeTrue();
    expect(validateDifficulty(['hiking' => 'EE', 'mtb' => 'EE']))->toBeFalse();
});

it('lega l accessibilita alla sua famiglia', function () {
    expect(validateDifficulty(['accessible-trails' => 'AEE']))->toBeTrue();
    expect(validateDifficulty(['accessible-trails' => 'EE']))->toBeFalse();
});

it('accetta per trekking e hiking esattamente gli stessi livelli', function () {
    foreach (['T', 'E', 'EE', 'EEA'] as $level) {
        expect(validateDifficulty(['trekking' => $level]))->toBeTrue();
        expect(validateDifficulty(['hiking' => $level]))->toBeTrue();
    }

    // «Medio» e' un livello di soft-mobility, che non e' una delle attivita' del modulo
    expect(validateDifficulty(['trekking' => 'Medio']))->toBeFalse();
    expect(validateDifficulty(['hiking' => 'Medio']))->toBeFalse();
    expect(validateDifficulty(['trekking' => 'BC']))->toBeFalse();
});

it('rifiuta un livello che appartiene a un altra scala di un attivita con la sua famiglia', function () {
    expect(validateDifficulty(['horse' => 'easy']))->toBeTrue();
    expect(validateDifficulty(['educational-trails' => 'easy']))->toBeTrue();
    expect(validateDifficulty(['mtb' => 'easy']))->toBeFalse();
    expect(validateDifficulty(['educational-trails' => 'EE']))->toBeFalse();
});

it('accetta i codici inglesi sulle tre scale a parole', function () {
    foreach (['horse', 'nordic-walking', 'educational-trails'] as $activity) {
        foreach (['easy', 'medium', 'hard'] as $level) {
            expect(validateDifficulty([$activity => $level]))->toBeTrue();
        }
    }
});

it('rifiuta le etichette italiane al posto dei codici', function () {
    // Prima erano il valore da mandare: ora il contratto dell'API e' il codice.
    expect(validateDifficulty(['horse' => 'Facile']))->toBeFalse();
    expect(validateDifficulty(['horse' => 'Difficile']))->toBeFalse();
    expect(validateDifficulty(['nordic-walking' => 'Impegnativo']))->toBeFalse();
    expect(validateDifficulty(['educational-trails' => 'Medio']))->toBeFalse();
});

it('non accetta i codici inglesi sulle scale a sigla', function () {
    expect(validateDifficulty(['hiking' => 'easy']))->toBeFalse();
    expect(validateDifficulty(['mtb' => 'hard']))->toBeFalse();
    expect(validateDifficulty(['accessible-trails' => 'medium']))->toBeFalse();
});

it('accetta trekking e hiking con livelli diversi', function () {
    // Condividono i valori ammessi, non il livello scelto: lo stesso sentiero
    // puo' essere `E` a trekking e `EEA` a escursionismo.
    expect(validateDifficulty(['trekking' => 'E', 'hiking' => 'EEA']))->toBeTrue();
    expect(validateDifficulty(['trekking' => 'EE', 'hiking' => 'EE']))->toBeTrue();
});

it('solleva un eccezione, non un rifiuto, se la famiglia non esiste a database', function () {
    $family = TaxonomyTerm::query()->where('identifier', 'mtb-difficulty')->firstOrFail();
    TaxonomyTerm::query()->where('parent_id', $family->id)->delete();
    $family->delete();

    expect(fn () => validateDifficulty(['mtb' => 'BC']))
        ->toThrow(MissingDifficultyFamilyException::class, 'mtb-difficulty');
});

it('rifiuta un valore che non e una mappa di stringhe', function () {
    expect(validateDifficulty('EE'))->toBeFalse();
    expect(validateDifficulty(['hiking' => ['EE']]))->toBeFalse();
    expect(validateDifficulty(['hiking' => '']))->toBeFalse();
    expect(validateDifficulty(['EE']))->toBeFalse();
});

/**
 * Il contratto dell'API, attivita' per attivita': questo elenco e' quello che
 * sta nella documentazione e quello che il SUS riceve. Un valore tolto o
 * aggiunto qui senza aggiornare la doc fa fallire il test.
 *
 * @return array<string, list<string>>
 */
function allowedDifficultyLevels(): array
{
    return [
        'trekking' => ['T', 'E', 'EE', 'EEA'],
        'hiking' => ['T', 'E', 'EE', 'EEA'],
        'mtb' => ['TC', 'MC', 'BC', 'OC', 'EC'],
        'horse' => ['easy', 'medium', 'hard'],
        'accessible-trails' => ['AT', 'AE', 'AEE'],
        'nordic-walking' => ['easy', 'medium', 'hard'],
        'educational-trails' => ['easy', 'medium', 'hard'],
    ];
}

it('accetta tutti i livelli ammessi di ogni attivita, e nessun altro', function () {
    $allowed = allowedDifficultyLevels();

    expect(array_keys($allowed))->toEqualCanonicalizing(app(ApplicationSchema::class)->activities());

    $everyLevel = array_unique(array_merge(...array_values($allowed)));

    foreach ($allowed as $activity => $levels) {
        foreach ($levels as $level) {
            expect(validateDifficulty([$activity => $level]))
                ->toBeTrue("{$activity} deve accettare {$level}");
        }

        foreach (array_diff($everyLevel, $levels) as $foreign) {
            expect(validateDifficulty([$activity => $foreign]))
                ->toBeFalse("{$activity} non deve accettare {$foreign}");
        }
    }
});

it('e agganciata alle regole del campo difficulty dello schema', function () {
    $rules = app(ApplicationSchema::class)->rules()['difficulty'];

    expect(collect($rules)->contains(fn ($rule) => $rule instanceof DifficultyMatchesActivities))->toBeTrue();
});

it('riconosce il livello dall identifier, non dall etichetta italiana', function () {
    // L'etichetta si rinomina da Nova: il contratto verso il SUS non puo' dipenderne.
    TaxonomyTerm::query()
        ->where('identifier', 'nordic-walking-difficulty-hard')
        ->firstOrFail()
        ->update(['name' => ['it' => 'Difficile']]);

    expect(validateDifficulty(['nordic-walking' => 'hard']))->toBeTrue();
    expect(validateDifficulty(['nordic-walking' => 'Difficile']))->toBeFalse();
});
