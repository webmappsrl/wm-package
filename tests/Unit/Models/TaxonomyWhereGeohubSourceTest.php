<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        // ottenere davvero una collisione sul valore 'corsica' si forza il
        // campo bypassando gli eventi del modello con saveQuietly().
        $existing = new TaxonomyWhere(['name' => 'Corsica esistente']);
        $existing->forceFill(['identifier' => 'corsica']);
        $existing->saveQuietly();

        $taxonomyWhere = new TaxonomyWhere;
        $identifier = $taxonomyWhere->withCollisionCounter('corsica');

        $this->assertSame('corsica-2', $identifier);
    }

    public function test_with_collision_counter_returns_base_when_free(): void
    {
        $taxonomyWhere = new TaxonomyWhere;
        $identifier = $taxonomyWhere->withCollisionCounter('francia');

        $this->assertSame('francia', $identifier);
    }
}
