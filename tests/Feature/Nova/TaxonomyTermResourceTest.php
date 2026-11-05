<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Nova\Contracts\QueryBuilder;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\MultiSelect as NativeMultiSelect;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\CreateResourceRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\UpdateResourceRequest;
use Laravel\Nova\Query\Builder;
use Wm\WmPackage\Models\EcTrack as EcTrackModel;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\EcTrack;
use Wm\WmPackage\Nova\Fields\Choice\Choice;
use Wm\WmPackage\Nova\TaxonomyTerm as TaxonomyTermResource;

uses(DatabaseTransactions::class);

// Il costruttore delle Resource con Images arriva a StorageService, che senza shard_name va in TypeError.
beforeEach(fn () => config(['wm-package.shard_name' => 'test_shard']));

function makeFamilyWithTerms(string $identifier, bool $singleChoice, array $terms): TaxonomyTerm
{
    $taxonomy = TaxonomyTerm::create([
        'identifier' => $identifier,
        'name' => ['it' => $identifier],
        'single_choice' => $singleChoice,
    ]);

    foreach ($terms as $termIdentifier => $sortOrder) {
        TaxonomyTerm::create([
            'identifier' => $termIdentifier,
            'parent_id' => $taxonomy->id,
            'name' => ['it' => strtoupper($termIdentifier)],
            'sort_order' => $sortOrder,
        ]);
    }

    return $taxonomy;
}

function ecTrackResource(): EcTrack
{
    return new EcTrack(new EcTrackModel);
}

/**
 * Il campo con cui si sceglie, fra i due che ogni tassonomia produce.
 *
 * `makeTermFieldsForTaxonomies()` ne restituisce due per tassonomia: un testo con l'icona, che si
 * vede solo sul dettaglio, e il `Choice` del form. Prendere `[0]` darebbe il primo, che non si
 * compila e il cui `fill()` restituisce null.
 */
function taxonomyFormField(array $identifiers): Choice
{
    return collect(ecTrackResource()->makeTermFieldsForTaxonomies($identifiers))
        ->first(fn ($field) => $field instanceof Choice);
}

it('genera due campi per tassonomia: il testo del dettaglio e il Choice del form', function () {
    makeFamilyWithTerms('accessibility-difficulty', true, ['diff-a' => 1]);

    $fields = ecTrackResource()->makeTermFieldsForTaxonomies(['accessibility-difficulty']);

    // Il campo del form mostrerebbe gli id sul dettaglio: lì va un testo con nome e icona.
    expect($fields)->toHaveCount(2)
        ->and($fields[0])->toBeInstanceOf(Text::class)
        ->and($fields[1])->toBeInstanceOf(Choice::class)
        // Niente Select nativa: il Choice disegna l'icona accanto all'etichetta.
        ->and($fields[1])->not->toBeInstanceOf(Select::class)
        // A scelta singola il Choice non è multiplo.
        ->and($fields[1]->jsonSerialize()['multiple'] ?? false)->toBeFalse();
});

it('una tassonomia a scelta multipla produce un Choice multiplo, mai una MultiSelect nativa', function () {
    makeFamilyWithTerms('accepted-disabilities', false, ['dis-a' => 1]);

    $field = taxonomyFormField(['accepted-disabilities']);

    expect($field)->toBeInstanceOf(Choice::class)
        ->and($field)->not->toBeInstanceOf(NativeMultiSelect::class)
        ->and($field->jsonSerialize()['multiple'] ?? false)->toBeTrue();
});

it('ordina le opzioni per sort_order e non per id o per nome', function () {
    // Creati in ordine diverso da quello della scala: T < E < EE.
    makeFamilyWithTerms('hiking-difficulty', true, ['diff-ee' => 3, 'diff-t' => 1, 'diff-e' => 2]);

    $field = taxonomyFormField(['hiking-difficulty']);
    $labels = collect($field->jsonSerialize()['options'])->pluck('label')->all();

    expect($labels)->toBe(['DIFF-T', 'DIFF-E', 'DIFF-EE']);
});

it('salta un identifier di tassonomia che non esiste', function () {
    makeFamilyWithTerms('exists', true, ['exists-a' => 1]);

    $fields = ecTrackResource()->makeTermFieldsForTaxonomies(['non-esiste', 'exists']);

    // Due campi, e sono quelli dell'unica tassonomia che esiste.
    expect($fields)->toHaveCount(2);
});

it('salva il termine scelto e toglie solo quelli della propria tassonomia', function () {
    makeFamilyWithTerms('single', true, ['single-a' => 1, 'single-b' => 2]);
    makeFamilyWithTerms('other', true, ['other-a' => 1]);
    $track = EcTrackModel::factory()->createQuietly();
    $track->taxonomyTerms()->attach(TaxonomyTerm::where('identifier', 'other-a')->value('id'));
    $track->taxonomyTerms()->attach(TaxonomyTerm::where('identifier', 'single-a')->value('id'));

    $field = taxonomyFormField(['single']);
    $b = TaxonomyTerm::where('identifier', 'single-b')->value('id');

    $callback = $field->fill(NovaRequest::create('/', 'PUT', [$field->attribute => $b]), $track);
    expect($callback)->toBeCallable();
    $callback();

    $track->unsetRelation('taxonomyTerms');
    expect($track->terms('single')->pluck('identifier')->all())->toBe(['single-b'])
        ->and($track->terms('other')->pluck('identifier')->all())->toBe(['other-a']);
});

it('svuota la propria tassonomia quando non si sceglie nulla', function () {
    makeFamilyWithTerms('single', true, ['single-a' => 1]);
    makeFamilyWithTerms('other', true, ['other-a' => 1]);
    $track = EcTrackModel::factory()->createQuietly();
    $track->taxonomyTerms()->attach(TaxonomyTerm::whereIn('identifier', ['single-a', 'other-a'])->pluck('id'));

    $field = taxonomyFormField(['single']);
    ($field->fill(NovaRequest::create('/', 'PUT', [$field->attribute => '']), $track))();

    $track->unsetRelation('taxonomyTerms');
    expect($track->terms('single'))->toHaveCount(0)
        ->and($track->terms('other')->pluck('identifier')->all())->toBe(['other-a']);
});

it('ignora un id che non appartiene alla tassonomia del campo', function () {
    makeFamilyWithTerms('single', true, ['single-a' => 1]);
    makeFamilyWithTerms('other', true, ['other-a' => 1]);
    $track = EcTrackModel::factory()->createQuietly();
    $field = taxonomyFormField(['single']);
    $foreign = TaxonomyTerm::where('identifier', 'other-a')->value('id');

    ($field->fill(NovaRequest::create('/', 'PUT', [$field->attribute => $foreign]), $track))();

    $track->unsetRelation('taxonomyTerms');
    expect($track->taxonomyTerms)->toHaveCount(0);
});

it('salva più termini su una tassonomia a scelta multipla', function () {
    makeFamilyWithTerms('multi', false, ['multi-a' => 1, 'multi-b' => 2]);
    $track = EcTrackModel::factory()->createQuietly();
    $field = taxonomyFormField(['multi']);
    $ids = TaxonomyTerm::whereIn('identifier', ['multi-a', 'multi-b'])->pluck('id')->all();

    ($field->fill(NovaRequest::create('/', 'PUT', [$field->attribute => json_encode($ids)]), $track))();

    $track->unsetRelation('taxonomyTerms');
    expect($track->terms('multi'))->toHaveCount(2);
});

it('mostra il termine già assegnato', function () {
    makeFamilyWithTerms('single', true, ['single-a' => 1, 'single-b' => 2]);
    $track = EcTrackModel::factory()->createQuietly();
    $b = TaxonomyTerm::where('identifier', 'single-b')->value('id');
    $track->taxonomyTerms()->attach($b);
    $field = taxonomyFormField(['single']);

    $field->resolve($track->fresh());

    expect((int) $field->value)->toBe((int) $b);
});

it('non tocca i campi del tab Accessibility', function () {
    // Guardia: in questo ticket il tab Accessibility non si tocca. Oggi ha 16 campi (data di verifica,
    // PDF e 14 campi access_*); se il numero cambia, qualcuno ha toccato il tab.
    $method = new ReflectionMethod(EcTrack::class, 'getAccessibilityTabFields');
    $method->setAccessible(true);

    expect($method->invoke(ecTrackResource()))->toHaveCount(16);
});

it('accetta solo identifier in kebab-case per una tassonomia, con i due punti dell\'import', function (string $identifier, bool $valid) {
    $rules = collect((new TaxonomyTermResource(new TaxonomyTerm))->fields(NovaRequest::create('/')))
        ->first(fn (Field $field) => $field->attribute === 'identifier')
        ->getRules(NovaRequest::create('/'));

    expect(Validator::make(['identifier' => $identifier], $rules)->passes())->toBe($valid);
})->with([
    ['hiking-difficulty', true],
    ['sardegnasentieri:tid:123', true],
    ['abc123', true],
    ['con.punto', false],
    ['Maiuscola', false],
    ['con spazio', false],
    ['-iniziale', false],
    ['doppio--trattino', false],
    ['finale-', false],
    ["con-a-capo\n", false],
    ['', false],
]);

it('per un termine il formato non e\' imposto: un punto e\' un valore, non una chiave dell\'indice', function () {
    $taxonomy = TaxonomyTerm::create(['identifier' => 'fam', 'name' => ['it' => 'Fam']]);
    $request = NovaRequest::create('/', 'POST', ['parent' => $taxonomy->id]);
    $rules = collect((new TaxonomyTermResource(new TaxonomyTerm))->fields($request))
        ->first(fn (Field $field) => $field->attribute === 'identifier')
        ->getRules($request);

    expect(Validator::make(['identifier' => 'IT.A'], $rules)->passes())->toBeTrue();
});

it('il model rifiuta un identifier di tassonomia con il punto, ma accetta un termine con il punto', function () {
    expect(fn () => TaxonomyTerm::create(['identifier' => 'con.punto', 'name' => ['it' => 'x']]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TaxonomyTerm::create(['identifier' => "fam\n", 'name' => ['it' => 'x']]))
        ->toThrow(InvalidArgumentException::class);

    $taxonomy = TaxonomyTerm::create(['identifier' => 'fam', 'name' => ['it' => 'Fam']]);
    $term = TaxonomyTerm::create(['identifier' => 'IT.A', 'parent_id' => $taxonomy->id, 'name' => ['it' => 'x']]);

    expect($term->exists)->toBeTrue();
});

function novaTermRequest(string $requestClass, array $input, ?int $resourceId = null): NovaRequest
{
    // Il provider core di Nova non e' caricato nei test: le Resource ne risolvono il QueryBuilder.
    app()->bind(QueryBuilder::class, fn ($app, $parameters) => new Builder(...$parameters));

    $request = $requestClass::create('/nova-api/taxonomy-terms', $resourceId ? 'PUT' : 'POST', $input);
    $request->setRouteResolver(function () use ($request, $resourceId) {
        $route = new Route(['POST'], 'nova-api/{resource}/{resourceId?}', []);
        $route->bind($request);
        $route->setParameter('resource', 'taxonomy-terms');
        if ($resourceId) {
            $route->setParameter('resourceId', $resourceId);
        }

        return $route;
    });

    return $request;
}

it('la regola unique in creazione blocca un identifier gia\' usato', function () {
    TaxonomyTerm::create(['identifier' => 'esistente', 'name' => ['it' => 'x']]);

    $request = novaTermRequest(CreateResourceRequest::class, ['identifier' => 'esistente']);
    $rules = TaxonomyTermResource::rulesForCreation($request);

    expect(Validator::make(['identifier' => 'esistente'], ['identifier' => $rules['identifier']])->passes())->toBeFalse()
        ->and(Validator::make(['identifier' => 'nuovo'], ['identifier' => $rules['identifier']])->passes())->toBeTrue();
});

it('la regola unique in modifica sostituisce {{resourceId}} e non blocca la riga su se stessa', function () {
    $self = TaxonomyTerm::create(['identifier' => 'mia', 'name' => ['it' => 'x']]);
    TaxonomyTerm::create(['identifier' => 'altra', 'name' => ['it' => 'x']]);

    $request = novaTermRequest(UpdateResourceRequest::class, ['identifier' => 'mia'], $self->id);
    $rules = TaxonomyTermResource::rulesForUpdate($request, new TaxonomyTermResource($self))['identifier'];

    expect(collect($rules)->filter(fn ($r) => is_string($r))->implode('|'))
        ->toContain("unique:taxonomy_terms,identifier,{$self->id}")
        ->not->toContain('{{resourceId}}')
        ->and(Validator::make(['identifier' => 'mia'], ['identifier' => $rules])->passes())->toBeTrue()
        ->and(Validator::make(['identifier' => 'altra'], ['identifier' => $rules])->passes())->toBeFalse();
});

function parentFieldFor(TaxonomyTerm $term, NovaRequest $request): Field
{
    return collect((new TaxonomyTermResource($term))->fields($request))
        ->first(fn (Field $f) => $f->attribute === 'parent' || $f->attribute === 'parent_id');
}

// Le regole dichiarate dalla Resource, senza quelle che BelongsTo aggiunge da se' (cerca il padre via Nova).
function parentOwnRules(Field $field, NovaRequest $request): array
{
    $rules = (new ReflectionProperty($field, 'rules'))->getValue($field);

    return is_callable($rules) ? $rules($request) : $rules;
}

it('la select della tassonomia non offre la riga corrente ne i termini', function () {
    $taxonomy = makeFamilyWithTerms('fam-a', true, ['fam-a-1' => 1]);
    $other = TaxonomyTerm::create(['identifier' => 'fam-b', 'name' => ['it' => 'B']]);

    $request = novaTermRequest(UpdateResourceRequest::class, [], $taxonomy->id);
    $field = parentFieldFor($taxonomy, $request);
    $callback = (new ReflectionProperty($field, 'relatableQueryCallback'))->getValue($field);

    $query = TaxonomyTerm::query();
    $callback($request, $query);

    expect($query->pluck('identifier')->all())->toBe(['fam-b']);
});

it('una tassonomia che ha gia\' dei termini non puo\' essere messa sotto un\'altra: messaggio di validazione, non eccezione', function () {
    $taxonomy = makeFamilyWithTerms('fam-a', true, ['fam-a-1' => 1]);
    $other = TaxonomyTerm::create(['identifier' => 'fam-b', 'name' => ['it' => 'B']]);

    $request = novaTermRequest(UpdateResourceRequest::class, ['parent' => $other->id], $taxonomy->id);
    $rules = parentOwnRules(parentFieldFor($taxonomy, $request), $request);

    $validator = Validator::make(['parent' => $other->id], ['parent' => $rules]);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('parent'))->toContain('already has terms');
});

it('una tassonomia senza termini puo\' avere un padre senza errori di validazione', function () {
    $empty = TaxonomyTerm::create(['identifier' => 'fam-vuota', 'name' => ['it' => 'V']]);
    $other = TaxonomyTerm::create(['identifier' => 'fam-b', 'name' => ['it' => 'B']]);

    $request = novaTermRequest(UpdateResourceRequest::class, ['parent' => $other->id], $empty->id);
    $rules = parentOwnRules(parentFieldFor($empty, $request), $request);

    expect(Validator::make(['parent' => $other->id], ['parent' => $rules])->passes())->toBeTrue();
});
