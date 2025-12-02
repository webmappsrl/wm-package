<?php

namespace Wm\WmPackage\Tests\Unit\Nova;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Filters\AppFilter;
use Wm\WmPackage\Tests\TestCase;

class AppFilterTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Configura Spatie Permission per i test
        config([
            'permission.models.permission' => Permission::class,
            'permission.models.role' => Role::class,
            'permission.cache.key' => 'spatie.permission.cache',
            'permission.cache.store' => 'default',
            'permission.teams' => false,
            'permission.column_names.role_pivot_key' => 'role_id',
            'permission.column_names.permission_pivot_key' => 'permission_id',
            'permission.column_names.model_morph_key' => 'model_id',
            'permission.column_names.team_foreign_key' => 'team_id',
            'permission.table_names.roles' => 'roles',
            'permission.table_names.permissions' => 'permissions',
            'permission.table_names.model_has_permissions' => 'model_has_permissions',
            'permission.table_names.model_has_roles' => 'model_has_roles',
            'permission.table_names.role_has_permissions' => 'role_has_permissions',
        ]);

        // Morph map necessario perché User::getMorphClass() restituisce 'App\Models\User' ma nel wm-package
        // standalone la classe reale è Wm\WmPackage\Models\User. Potrebbe essere aggiunto al WmPackageServiceProvider.
        Relation::morphMap([
            'App\Models\User' => User::class,
        ]);

        Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']);
    }

    public function test_apply_filters_query_by_app_id_when_model_has_app_id_column(): void
    {
        $filter = new AppFilter;
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $app = App::factory()->create(['user_id' => $user->id]);
        $otherApp = App::factory()->create(['user_id' => $user->id]);

        $geojson1 = json_encode([
            'type' => 'Point',
            'coordinates' => [11.0, 45.0, 100.0],
        ]);
        $geojson2 = json_encode([
            'type' => 'Point',
            'coordinates' => [12.0, 46.0, 200.0],
        ]);
        $geojson3 = json_encode([
            'type' => 'Point',
            'coordinates' => [13.0, 47.0, 300.0],
        ]);

        $poi1 = UgcPoi::create([
            'app_id' => $app->id,
            'user_id' => $user->id,
            'name' => 'POI 1',
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson1}')"),
            'properties' => [],
        ]);

        $poi2 = UgcPoi::create([
            'app_id' => $app->id,
            'user_id' => $user->id,
            'name' => 'POI 2',
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson2}')"),
            'properties' => [],
        ]);

        $poi3 = UgcPoi::create([
            'app_id' => $otherApp->id,
            'user_id' => $user->id,
            'name' => 'POI 3',
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson3}')"),
            'properties' => [],
        ]);

        $query = UgcPoi::query();
        $request = new Request;

        $filteredQuery = $filter->apply($request, $query, $app->id);
        $results = $filteredQuery->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $poi1->id));
        $this->assertTrue($results->contains('id', $poi2->id));
        $this->assertFalse($results->contains('id', $poi3->id));
    }

    public function test_apply_filters_query_by_app_id_when_model_has_no_app_id_column(): void
    {
        $filter = new AppFilter;
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $app = App::factory()->create(['user_id' => $user->id]);
        $otherApp = App::factory()->create(['user_id' => $user->id]);

        $geojson1 = json_encode([
            'type' => 'Point',
            'coordinates' => [11.0, 45.0, 100.0],
        ]);
        $geojson2 = json_encode([
            'type' => 'LineString',
            'coordinates' => [[12.0, 46.0, 200.0], [13.0, 47.0, 300.0]],
        ]);

        UgcPoi::create([
            'app_id' => $app->id,
            'user_id' => $user->id,
            'name' => 'POI 1',
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson1}')"),
            'properties' => [],
        ]);

        UgcTrack::create([
            'app_id' => $otherApp->id,
            'user_id' => $user->id,
            'name' => 'Track for other app',
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson2}')"),
            'properties' => [],
        ]);

        $query = User::query();
        $request = new Request;

        // Il filtro cerca app_id direttamente nella tabella, quindi per User (senza app_id)
        // la query non restituirà risultati perché la colonna non esiste
        $filteredQuery = $filter->apply($request, $query, $app->id);
        $results = $filteredQuery->get();

        // Il filtro attuale non gestisce modelli senza app_id, quindi restituisce 0 risultati
        $this->assertCount(0, $results);
    }

    public function test_options_returns_all_apps_for_administrator(): void
    {
        $filter = new AppFilter;

        $app1 = App::factory()->create(['name' => 'App Alpha']);
        $app2 = App::factory()->create(['name' => 'App Beta']);
        $app3 = App::factory()->create(['name' => 'App Gamma']);

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $request = NovaRequest::create('/nova-api/users/filters');
        $request->setUserResolver(fn () => $admin);

        $options = $filter->options($request);

        $this->assertArrayHasKey('App Alpha', $options);
        $this->assertArrayHasKey('App Beta', $options);
        $this->assertArrayHasKey('App Gamma', $options);
        $this->assertEquals($app1->id, $options['App Alpha']);
        $this->assertEquals($app2->id, $options['App Beta']);
        $this->assertEquals($app3->id, $options['App Gamma']);
    }

    public function test_options_returns_only_user_apps_for_non_administrator(): void
    {
        $filter = new AppFilter;

        $user = User::factory()->create();

        $userApp1 = App::factory()->create([
            'name' => 'User App 1',
            'sku' => 'user-app-1',
            'user_id' => $user->id,
        ]);
        $userApp2 = App::factory()->create([
            'name' => 'User App 2',
            'sku' => 'user-app-2',
            'user_id' => $user->id,
        ]);

        $otherUser = User::factory()->create();
        App::factory()->create([
            'name' => 'Other App',
            'sku' => 'other-app',
            'user_id' => $otherUser->id,
        ]);

        $request = NovaRequest::create('/nova-api/users/filters');
        $request->setUserResolver(fn () => $user);

        $options = $filter->options($request);

        $this->assertArrayHasKey('User App 1', $options);
        $this->assertArrayHasKey('User App 2', $options);
        $this->assertArrayNotHasKey('Other App', $options);
        $this->assertEquals($userApp1->id, $options['User App 1']);
        $this->assertEquals($userApp2->id, $options['User App 2']);
    }

    public function test_options_returns_empty_array_when_non_administrator_has_no_apps(): void
    {
        $filter = new AppFilter;

        $user = User::factory()->create();

        $otherUser = User::factory()->create();
        App::factory()->create([
            'name' => 'Other App',
            'user_id' => $otherUser->id,
        ]);

        $request = NovaRequest::create('/nova-api/users/filters');
        $request->setUserResolver(fn () => $user);

        $options = $filter->options($request);

        $this->assertIsArray($options);
        $this->assertEmpty($options);
    }
}
