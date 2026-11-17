<?php

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Filters\ChoiceFilter;

/*
 * Il filtro di base con il selettore di `Choice` (oc:8988): opzioni fornite dalla sottoclasse,
 * valori in stringa, valore vuoto che non filtra, valore di partenza diverso per modalità.
 */

uses(DatabaseTransactions::class);

/**
 * Una sottoclasse di prova con id interi, come le aree catastali di Forestas: registra il valore
 * che riceve e filtra per id.
 */
function choiceFilterWithIntegerIds(): ChoiceFilter
{
    return new class extends ChoiceFilter
    {
        public string|array|null $received = null;

        protected function choiceOptions(NovaRequest $request): array
        {
            return [
                ['value' => 12, 'label' => 'Dodici'],
                ['value' => 13, 'label' => 'Tredici', 'svg' => '<path d="M0 0"/>'],
            ];
        }

        protected function applyChoice(NovaRequest $request, Builder $query, string|array $value): Builder
        {
            $this->received = $value;

            return $query->whereIn('id', array_map('intval', (array) $value));
        }
    };
}

it('converte in stringa il valore delle opzioni e lascia il resto com è', function () {
    $options = choiceFilterWithIntegerIds()->options(NovaRequest::create('/'));

    expect($options)->toBe([
        ['value' => '12', 'label' => 'Dodici'],
        ['value' => '13', 'label' => 'Tredici', 'svg' => '<path d="M0 0"/>'],
    ]);
});

it('un valore vuoto non tocca la query', function (mixed $empty) {
    $filter = choiceFilterWithIntegerIds()->multiple();
    $query = EcTrack::query();
    $sqlBefore = $query->toSql();

    $filter->apply(NovaRequest::create('/'), $query, $empty);

    expect($query->toSql())->toBe($sqlBefore)
        ->and($filter->received)->toBeNull();
})->with([
    'stringa vuota' => '',
    'null' => null,
    'array vuoto' => [[]],
    'array di vuoti' => [['', null]],
]);

it('in multiple passa alla sottoclasse un array di stringhe, anche da id interi di un link', function () {
    $filter = choiceFilterWithIntegerIds()->multiple();

    $filter->apply(NovaRequest::create('/'), EcTrack::query(), [12, 13, 12]);

    expect($filter->received)->toBe(['12', '13']);
});

it('in single passa alla sottoclasse una stringa', function () {
    $filter = choiceFilterWithIntegerIds();

    $filter->apply(NovaRequest::create('/'), EcTrack::query(), 12);

    expect($filter->received)->toBe('12');
});

it('il valore di partenza è un array vuoto in multiple e una stringa vuota in single', function () {
    expect(choiceFilterWithIntegerIds()->multiple()->default())->toBe([])
        ->and(choiceFilterWithIntegerIds()->default())->toBe('');
});

it('manda al componente modalità, segnaposto e la chiave di default', function () {
    $filter = choiceFilterWithIntegerIds()->multiple()->searchPlaceholder('Cerca area');

    $json = $filter->jsonSerialize();

    expect($json['component'])->toBe('choice-filter')
        ->and($json['multiple'])->toBeTrue()
        ->and($json['searchPlaceholder'])->toBe('Cerca area')
        ->and($json['currentValue'])->toBe([])
        ->and($json['class'])->toBe($filter::class)
        ->and(array_column($json['options'], 'value'))->toBe(['12', '13']);
});
