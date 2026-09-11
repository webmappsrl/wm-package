<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Models\TaxonomyWhere;

class TaxonomyWhereGeohubSourceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_get_source_id_reads_geohub_id(): void
    {
        $taxonomyWhere = new TaxonomyWhere(['properties' => ['geohub_id' => 42]]);

        $this->assertSame('42', $taxonomyWhere->getSourceId());
    }

    public function test_with_collision_counter_is_publicly_callable(): void
    {
        // `identifier` non e' in $fillable di TaxonomyWhere (lo scrive
        // l'observer, non il mass-assignment, vedi oc:8469) e l'observer
        // rigenera comunque l'identifier in creating() se assente. Per
        // ottenere davvero una collisione si forza il campo bypassando gli
        // eventi del modello con saveQuietly().
        // Identifier dinamico: il DB di sviluppo condiviso contiene dati QA
        // reali con identifier fissi ('corsica' incluso) — un valore
        // letterale collide con l'indice unique. Stesso pattern usato
        // altrove nel piano oc:8486 (Task 1/2 e Task 3).
        $baseIdentifier = 'corsica-'.Str::lower(Str::random(8));

        $existing = new TaxonomyWhere(['name' => 'Corsica esistente']);
        $existing->forceFill(['identifier' => $baseIdentifier]);
        $existing->saveQuietly();

        $taxonomyWhere = new TaxonomyWhere;
        $identifier = $taxonomyWhere->withCollisionCounter($baseIdentifier);

        $this->assertSame($baseIdentifier.'-2', $identifier);
    }

    public function test_with_collision_counter_returns_base_when_free(): void
    {
        // Identifier dinamico per lo stesso motivo del test precedente —
        // 'francia' collide con dati QA reali del DB condiviso.
        $baseIdentifier = 'francia-'.Str::lower(Str::random(8));

        $taxonomyWhere = new TaxonomyWhere;
        $identifier = $taxonomyWhere->withCollisionCounter($baseIdentifier);

        $this->assertSame($baseIdentifier, $identifier);
    }
}
