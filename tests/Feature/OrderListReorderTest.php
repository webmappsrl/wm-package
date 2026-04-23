<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Fields\OrderList\src\Http\Controllers\OrderListController;
use Wm\WmPackage\Nova\Fields\OrderList\src\OrderList;

it('normalizza rank 1..n quando riordino i layer di una app', function () {
    /** @var App $app */
    $app = App::factory()->create();

    /** @var Layer $l1 */
    $l1 = Layer::factory()->create(['app_id' => $app->id, 'rank' => 10]);
    /** @var Layer $l2 */
    $l2 = Layer::factory()->create(['app_id' => $app->id, 'rank' => 20]);
    /** @var Layer $l3 */
    $l3 = Layer::factory()->create(['app_id' => $app->id, 'rank' => 30]);

    // Bypass Gate (il controller autorizza su un modello passato via signed params)
    Gate::shouldReceive('authorize')->once();

    $controller = app(OrderListController::class);

    $model = base64_encode(Layer::class);
    $model = rtrim(strtr($model, '+/', '-_'), '=');
    $authModel = base64_encode(App::class);
    $authModel = rtrim(strtr($authModel, '+/', '-_'), '=');

    $url = url("/nova-vendor/order-list/reorder/{$model}/app_id/{$app->id}/rank");
    $signed = URL::signedUrl($url, [
        'authorizeAbility' => 'update',
        'authorizeModel' => $authModel,
        'authorizeId' => (string) $app->id,
    ]);

    $request = Request::create(
        $signed,
        'POST',
        ['ids' => [$l3->id, $l1->id, $l2->id]]
    );

    expect($request->hasValidSignature())->toBeTrue();

    $controller->reorder($request, $model, 'app_id', (string) $app->id, 'rank');

    $ranks = DB::table('layers')
        ->whereIn('id', [$l1->id, $l2->id, $l3->id])
        ->pluck('rank', 'id')
        ->map(fn ($v) => (int) $v)
        ->all();

    expect($ranks[$l3->id])->toBe(1)
        ->and($ranks[$l1->id])->toBe(2)
        ->and($ranks[$l2->id])->toBe(3);
});

it('assegna rank in creazione per app (non globale)', function () {
    /** @var App $app1 */
    $app1 = App::factory()->create();
    /** @var App $app2 */
    $app2 = App::factory()->create();

    // Primo layer per app1 => 1
    $a1l1 = Layer::factory()->create(['app_id' => $app1->id]);
    // Primo layer per app2 => 1
    $a2l1 = Layer::factory()->create(['app_id' => $app2->id]);
    // Secondo layer per app1 => 2
    $a1l2 = Layer::factory()->create(['app_id' => $app1->id]);

    expect((int) $a1l1->rank)->toBe(1)
        ->and((int) $a2l1->rank)->toBe(1)
        ->and((int) $a1l2->rank)->toBe(2);
});

it('include il colore negli items quando configurato con ->color()', function () {
    /** @var App $app */
    $app = App::factory()->create();

    /** @var Layer $withColor */
    $withColor = Layer::factory()->create([
        'app_id' => $app->id,
        'rank' => 1,
        'properties' => ['color' => '#aabbcc'],
    ]);

    /** @var Layer $withoutColor */
    $withoutColor = Layer::factory()->create([
        'app_id' => $app->id,
        'rank' => 2,
        'properties' => [],
    ]);

    $field = OrderList::make('Layer Rank')
        ->model(Layer::class)
        ->scope('app_id', fn ($resource) => (int) $resource->id)
        ->orderColumn('rank')
        ->labelColumn('name')
        ->color(fn (Layer $layer) => $layer->getStrokeColorHex());

    $field->resolve($app);

    $meta = $field->meta();

    expect($meta)->toHaveKey('items');
    $items = collect($meta['items'])->keyBy('id')->all();

    expect($items[$withColor->id])->toHaveKey('color')
        ->and(strtolower($items[$withColor->id]['color']))->toBe('#aabbcc')
        ->and($items[$withoutColor->id])->not->toHaveKey('color');
});

it('non include il colore negli items quando ->color() non è configurato', function () {
    /** @var App $app */
    $app = App::factory()->create();

    Layer::factory()->create([
        'app_id' => $app->id,
        'rank' => 1,
        'properties' => ['color' => '#112233'],
    ]);

    $field = OrderList::make('Layer Rank')
        ->model(Layer::class)
        ->scope('app_id', fn ($resource) => (int) $resource->id)
        ->orderColumn('rank')
        ->labelColumn('name');

    $field->resolve($app);

    $meta = $field->meta();

    expect($meta)->toHaveKey('items');
    foreach ($meta['items'] as $item) {
        expect($item)->not->toHaveKey('color');
    }
});
