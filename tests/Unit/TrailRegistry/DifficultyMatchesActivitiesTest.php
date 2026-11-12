<?php

use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Exceptions\MissingDifficultyFamilyException;
use Wm\WmPackage\TrailRegistry\Rules\DifficultyMatchesActivities;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * Crea una famiglia di difficolta' con i suoi livelli (il nome italiano del termine).
 *
 * @param  list<string>  $levels
 */
function seedDifficultyFamily(string $identifier, array $levels): void
{
    $family = TaxonomyTerm::create([
        'identifier' => $identifier,
        'name' => ['it' => $identifier],
    ]);

    foreach ($levels as $level) {
        TaxonomyTerm::create([
            'identifier' => 'sardegnasentieri:tid:'.$identifier.'-'.$level,
            'parent_id' => $family->id,
            'name' => ['it' => $level],
        ]);
    }
}

function validateDifficulty(mixed $difficulty): bool
{
    return Validator::make(['difficulty' => $difficulty], ['difficulty' => [new DifficultyMatchesActivities]])->passes();
}

beforeEach(function () {
    seedDifficultyFamily('hiking-difficulty', ['T', 'E', 'EE', 'EEA']);
    seedDifficultyFamily('soft-mobility-difficulty', ['Facile', 'Medio']);
    seedDifficultyFamily('mtb-difficulty', ['TC', 'MC', 'BC']);
    seedDifficultyFamily('horse-difficulty', ['Facile', 'Medio', 'Difficile']);
    seedDifficultyFamily('nordic-walking-difficulty', ['Facile', 'Medio', 'Impegnativo']);
    seedDifficultyFamily('educational-trails-difficulty', ['Facile', 'Medio', 'Impegnativo']);
    seedDifficultyFamily('inclusivity-difficulty', ['AT', 'AE', 'AEE']);
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
    // «Facile» esiste in horse, nordic-walking, educational-trails: non in mtb
    expect(validateDifficulty(['horse' => 'Facile']))->toBeTrue();
    expect(validateDifficulty(['educational-trails' => 'Facile']))->toBeTrue();
    expect(validateDifficulty(['mtb' => 'Facile']))->toBeFalse();
    expect(validateDifficulty(['educational-trails' => 'EE']))->toBeFalse();
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

it('e agganciata alle regole del campo difficulty dello schema', function () {
    $rules = app(ApplicationSchema::class)->rules()['difficulty'];

    expect(collect($rules)->contains(fn ($rule) => $rule instanceof DifficultyMatchesActivities))->toBeTrue();
});
