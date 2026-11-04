<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Http\Requests\CreateResourceRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\AbstractTaxonomyTermsResource;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'test_shard']);

    $this->family = TaxonomyTerm::create([
        'identifier' => 'warnings',
        'name' => ['it' => 'Avvertenze'],
    ]);
    $this->warning = TaxonomyTerm::create([
        'identifier' => 'sardegnasentieri:warning:4716',
        'parent_id' => $this->family->id,
        'name' => ['it' => 'Cinghiali'],
    ]);

    $other = TaxonomyTerm::create([
        'identifier' => 'route-type',
        'name' => ['it' => 'Tipo percorso'],
    ]);
    TaxonomyTerm::create([
        'identifier' => 'route-type-trail',
        'parent_id' => $other->id,
        'name' => ['it' => 'Sentiero'],
    ]);
});

/** Resource di prova: è tutto ciò che una sottoclasse deve dichiarare. */
class ProvaWarningsResource extends AbstractTaxonomyTermsResource
{
    public static function taxonomyIdentifier(): string
    {
        return 'warnings';
    }
}

function provaRequest(): NovaRequest
{
    return NovaRequest::create('/nova-api/prova-warnings-resources', 'GET');
}

/** Il default di un campo si risolve solo in una richiesta di creazione. */
function provaCreateRequest(): CreateResourceRequest
{
    return CreateResourceRequest::create('/nova-api/prova-warnings-resources/creation-fields', 'GET');
}

it('elenca solo i termini della propria tassonomia', function () {
    $identifiers = ProvaWarningsResource::indexQuery(provaRequest(), TaxonomyTerm::query())
        ->pluck('identifier')
        ->all();

    expect($identifiers)->toBe(['sardegnasentieri:warning:4716']);
});

it('non mostra niente finché la tassonomia non esiste', function () {
    $this->warning->delete();
    $this->family->delete();

    expect(ProvaWarningsResource::indexQuery(provaRequest(), TaxonomyTerm::query())->count())->toBe(0);
});

it('prende etichetta e titolo dal nome della tassonomia', function () {
    expect(ProvaWarningsResource::label())->toBe('Avvertenze')
        ->and(ProvaWarningsResource::singularLabel())->toBe('Avvertenze');
});

it('crea il termine dentro la propria tassonomia senza chiederla', function () {
    $fields = (new ProvaWarningsResource($this->warning))->fields(provaRequest());

    $parent = collect($fields)->first(fn ($f) => $f->attribute === 'parent_id');

    expect(collect($fields)->contains(fn ($f) => $f instanceof BelongsTo && $f->attribute === 'parent'))->toBeFalse()
        ->and($parent)->toBeInstanceOf(Hidden::class)
        ->and($parent->resolveDefaultValue(provaCreateRequest()))->toBe($this->family->id);
});

it('non impone ai termini il formato di identifier dei tassonomie', function () {
    // «sardegnasentieri:warning:4716» passerebbe comunque: il caso che conta è un identifier
    // con spazi e maiuscole, che su una tassonomia sarebbe un errore.
    $fields = (new ProvaWarningsResource(new TaxonomyTerm))->fields(provaRequest());
    $identifier = collect($fields)->first(fn ($f) => $f->attribute === 'identifier');

    $errors = [];
    foreach ($identifier->rules as $rule) {
        if (is_callable($rule)) {
            $rule('identifier', 'Cani Aggressivi', function ($message) use (&$errors) {
                $errors[] = $message;
            });
        }
    }

    expect($errors)->toBe([]);
});
