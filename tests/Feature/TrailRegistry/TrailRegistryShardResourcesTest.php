<?php

use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\FeaturesService;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode;
use Wm\WmPackage\WmPackageServiceProvider;

class ShardCodeResource extends TrailRegistryCode {}

class ShardApplicationResource extends TrailApplication {}

class ShardAnomalyResource extends TrailRegistryAnomaly {}

class ShardHiddenCodeResource extends TrailRegistryCode
{
    public static $displayInNavigation = false;
}

class ShardNoopAction extends Action {}

class ShardConfiguredCodeModel extends TrailRegistryCodeModel {}

class ShardConfiguredApplicationModel extends TrailApplicationModel {}

class ShardOwnCodeModel extends TrailRegistryCodeModel {}

class ShardCodeResourceWithOwnModel extends TrailRegistryCode
{
    public static $model = ShardOwnCodeModel::class;
}

/**
 * Nova::$resources e' una proprieta' statica che si accumula fra i test
 * (`Nova::resources()` fa `array_merge`, mai un reset): altri file — es.
 * MainMenuInjectionTest, che simula la registrazione dello shard — la
 * lasciano popolata con le Resource base. Senza azzerarla qui, un test di
 * questo file rischierebbe di vedere lo stato lasciato da un altro.
 */
beforeEach(function () {
    Nova::$resources = [];
});

it('il package non registra piu le resource del catasto', function () {
    // Il beforeEach ha azzerato Nova::$resources: qui si rifanno, a dominio
    // acceso, i passaggi con cui il provider registrava le Resource del
    // catasto — la registrazione dei domini accesi e quello che si aggiunge
    // servendo Nova — e si controlla che nessuna sia del catasto. Senza
    // rifarli il test guarderebbe un elenco azzerato e passerebbe comunque.
    // Che non stiano in src/Nova, scandita da resourcesIn(), lo presidia
    // TrailRegistryDomainRegistrationTest.
    config(['wm-package.features.trail_registry.enabled' => true]);
    expect(FeaturesService::enabledDomains())->toContain('trail_registry');
    expect(config('wm-package.features.trail_registry.nova_resources'))->toBeNull();

    $provider = app()->getProvider(WmPackageServiceProvider::class);
    (fn () => $this->registerEnabledDomains())->call($provider);
    ServingNova::dispatch(app(), Request::create('/'));

    $domainResources = collect(Nova::$resources)->filter(
        fn (string $resource) => in_array($resource::uriKey(), [
            'trail-applications', 'trail-registry-codes', 'trail-registry-anomalies',
        ], true)
    );

    expect($domainResources->all())->toBe([]);
});

it('a dominio acceso la sottoclasse dello shard e quella che Nova usa', function () {
    config(['wm-package.features.trail_registry.enabled' => true]);
    Nova::resources([ShardCodeResource::class]);

    expect(Nova::resourceForKey('trail-registry-codes'))->toBe(ShardCodeResource::class);
    expect(ShardCodeResource::availableForNavigation(Request::create('/')))->toBeTrue();
});

it('a dominio spento la sottoclasse non si vede e non si apre', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);
    $request = Request::create('/');
    $novaRequest = NovaRequest::create('/');
    $resource = new ShardCodeResource(new TrailRegistryCodeModel);

    expect(ShardCodeResource::availableForNavigation($request))->toBeFalse();
    expect(ShardCodeResource::authorizedToViewAny($request))->toBeFalse();
    expect($resource->authorizedToView($request))->toBeFalse();
    expect(ShardCodeResource::authorizedToCreate($request))->toBeFalse();
    expect($resource->authorizedToUpdate($request))->toBeFalse();
    expect($resource->authorizedToDelete($request))->toBeFalse();
    expect($resource->authorizedToRunAction($novaRequest, new ShardNoopAction))->toBeFalse();
});

it('a dominio spento un istanza in istruttoria non si modifica', function () {
    // TrailApplication ridefinisce authorizedToUpdate(), che quindi vince sul
    // trait: il controllo del dominio deve stare anche li'.
    config(['wm-package.features.trail_registry.enabled' => false]);
    $application = (new TrailApplicationModel)->forceFill(['status' => TrailApplicationStatus::UnderReview]);

    expect((new ShardApplicationResource($application))->authorizedToUpdate(Request::create('/')))->toBeFalse();
});

it('a dominio acceso le autorizzazioni restano quelle della Resource', function () {
    config(['wm-package.features.trail_registry.enabled' => true]);
    RolesAndPermissionsService::seedDatabase();
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $request = Request::create('/');
    $request->setUserResolver(fn () => $editor);
    $novaRequest = NovaRequest::create('/');
    $novaRequest->setUserResolver(fn () => $editor);
    $this->actingAs($editor);
    $application = (new TrailApplicationModel)->forceFill(['status' => TrailApplicationStatus::UnderReview]);

    expect((new ShardApplicationResource($application))->authorizedToUpdate($request))->toBeTrue();
    // Il registro dei codici nega sempre le scritture, acceso o spento.
    expect(ShardCodeResource::authorizedToCreate($request))->toBeFalse();
    expect((new ShardCodeResource(new TrailRegistryCodeModel))->authorizedToRunAction($novaRequest, new ShardNoopAction))->toBeTrue();
});

it('a dominio acceso un Validator non ottiene le autorizzazioni di un Editor', function () {
    config(['wm-package.features.trail_registry.enabled' => true]);
    RolesAndPermissionsService::seedDatabase();
    $validator = User::factory()->create();
    $validator->assignRole('Validator');
    $request = Request::create('/');
    $request->setUserResolver(fn () => $validator);
    $novaRequest = NovaRequest::create('/');
    $novaRequest->setUserResolver(fn () => $validator);
    $this->actingAs($validator);
    $application = (new TrailApplicationModel)->forceFill(['status' => TrailApplicationStatus::UnderReview]);

    expect((new ShardApplicationResource($application))->authorizedToUpdate($request))->toBeFalse();
    expect(ShardApplicationResource::authorizedToCreate($request))->toBeFalse();
    expect((new ShardCodeResource(new TrailRegistryCodeModel))->authorizedToRunAction($novaRequest, new ShardNoopAction))->toBeFalse();
});

it('a dominio acceso la navigazione rispetta displayInNavigation', function () {
    config(['wm-package.features.trail_registry.enabled' => true]);

    expect(ShardHiddenCodeResource::availableForNavigation(Request::create('/')))->toBeFalse();
});

it('la sottoclasse di TrailApplication resta raggiungibile con la stessa chiave', function () {
    Nova::resources([ShardApplicationResource::class]);

    expect(Nova::resourceForKey('trail-applications'))->toBe(ShardApplicationResource::class);
});

it('la sottoclasse di TrailRegistryAnomaly resta raggiungibile con la stessa chiave', function () {
    Nova::resources([ShardAnomalyResource::class]);

    expect(Nova::resourceForKey('trail-registry-anomalies'))->toBe(ShardAnomalyResource::class);
});

it('con il modello sostituito da config la voce di menu resta e newModel usa quel modello', function () {
    // `$model` della sottoclasse resta quello del package: per modello Nova
    // non la troverebbe, per uriKey si'.
    config([
        'wm-package.features.trail_registry.enabled' => true,
        'wm-package.features.trail_registry.models.code' => ShardConfiguredCodeModel::class,
    ]);
    Nova::resources([ShardCodeResource::class]);

    expect(ShardCodeResource::newModel())->toBeInstanceOf(ShardConfiguredCodeModel::class);

    Nova::$mainMenuCallback = fn (Request $request) => [];
    ServingNova::dispatch(app(), Request::create('/'));
    $menu = call_user_func(Nova::$mainMenuCallback, Request::create('/'));

    $catasto = collect($menu)->first(
        fn ($section) => $section instanceof MenuSection && (string) $section->name === __('Catasto')
    );

    expect($catasto)->not->toBeNull();
    expect(collect($catasto->items)->map(fn ($item) => (string) $item->name)->all())
        ->toBe([__('Registro dei codici')]);
});

it('se la sottoclasse cambia $model newModel usa quello, anche contro la config', function () {
    config(['wm-package.features.trail_registry.models.code' => ShardConfiguredCodeModel::class]);

    expect(ShardCodeResourceWithOwnModel::newModel())->toBeInstanceOf(ShardOwnCodeModel::class);
});

it('il BelongsTo Istanza trova la Resource dello shard per uriKey anche con il modello sostituito', function () {
    config(['wm-package.features.trail_registry.models.application' => ShardConfiguredApplicationModel::class]);
    Nova::resources([ShardApplicationResource::class, ShardCodeResource::class]);

    $field = collect((new ShardCodeResource(new TrailRegistryCodeModel))->fields(NovaRequest::create('/')))
        ->first(fn ($field) => $field->name === __('Istanza'));

    expect($field->resourceClass)->toBe(ShardApplicationResource::class);
});
