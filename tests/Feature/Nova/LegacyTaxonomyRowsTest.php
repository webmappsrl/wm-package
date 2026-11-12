<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Concerns\BuildsTaxonomyFields;

uses(DatabaseTransactions::class);

/**
 * Le opzioni delle tassonomie storiche si leggono solo nelle colonne che servono, e una volta
 * sola per richiesta: Nova costruisce i campi di una Resource una volta per ogni voce di un
 * elenco di relazione, e con `select *` sulle Zone (7.930 righe, 104 MB di cui 93 di geometry)
 * il menu di attach impiegava due minuti (oc:8974).
 */
function taxonomyFieldsHost(array $taxonomies): object
{
    return new class($taxonomies)
    {
        use BuildsTaxonomyFields;

        public $resource;

        public function __construct(private array $taxonomies)
        {
            $this->resource = new EcTrack;
        }

        protected function legacyTaxonomies(): array
        {
            return $this->taxonomies;
        }

        public function build(string $default = 'General'): array
        {
            return $this->makeLegacyTaxonomyFieldsByTab($default);
        }
    };
}

it('non legge la colonna geometry delle Zone', function () {
    TaxonomyWhere::query()->forceCreate(['name' => ['it' => 'Zona di prova'], 'identifier' => 'oc8974-zona']);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        // La query delle opzioni, non quella con cui Schema::hasColumn guarda le colonne.
        if (str_contains($q->sql, 'from "taxonomy_wheres"') && str_starts_with($q->sql, 'select')) {
            $queries[] = $q->sql;
        }
    });

    taxonomyFieldsHost([[
        'key' => 'wheres', 'relation' => 'taxonomyWheres', 'model' => TaxonomyWhere::class,
        'label' => 'Zones', 'tab' => 'General', 'hidden' => [],
    ]])->build();

    expect($queries)->not->toBeEmpty();
    expect($queries[0])->not->toContain('geometry');
    expect($queries[0])->toContain('"id"')->toContain('"name"')->toContain('"identifier"');
});

it('legge le righe una volta sola per quante costruzioni dei campi', function () {
    TaxonomyWhere::query()->forceCreate(['name' => ['it' => 'Zona di prova'], 'identifier' => 'oc8974-zona-2']);

    $letture = 0;
    DB::listen(function ($q) use (&$letture) {
        if (str_contains($q->sql, 'from "taxonomy_wheres"') && str_starts_with($q->sql, 'select')) {
            $letture++;
        }
    });

    $taxonomies = [[
        'key' => 'wheres', 'relation' => 'taxonomyWheres', 'model' => TaxonomyWhere::class,
        'label' => 'Zones', 'tab' => 'General', 'hidden' => [],
    ]];

    foreach (range(1, 5) as $i) {
        taxonomyFieldsHost($taxonomies)->build();
    }

    expect($letture)->toBe(1);
});

it('legge la colonna icon dove esiste, e il nome tradotto', function () {
    $activity = TaxonomyActivity::query()->forceCreate([
        'name' => ['it' => 'Attività di prova'],
        'identifier' => 'oc8974-attivita',
        'icon' => 'hiking',
    ]);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'from "taxonomy_activities"') && str_starts_with($q->sql, 'select')) {
            $queries[] = $q->sql;
        }
    });

    $fields = taxonomyFieldsHost([[
        'key' => 'activities', 'relation' => 'taxonomyActivities', 'model' => TaxonomyActivity::class,
        'label' => 'Activities', 'tab' => 'Activities', 'hidden' => [],
    ]])->build();

    // Su taxonomy_activities la colonna icon esiste e va letta: senza, le icone sparirebbero.
    expect($queries)->not->toBeEmpty();
    expect($queries[0])->toContain('"icon"');

    $choice = collect($fields['Activities'] ?? [])->last();
    $option = collect($choice->meta()['options'] ?? [])->firstWhere('value', (string) $activity->id);

    expect($option)->not->toBeNull();
    expect($option['label'])->toBe('Attività di prova');
});
