<?php

namespace Wm\WmPackage\Nova;

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Support\Facades\Config;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Color;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Laravel\Nova\Tabs\Tab;
use Marshmallow\Tiptap\Tiptap;
use Outl1ne\MultiselectField\Multiselect;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Enums\AppTiles;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App as ModelsApp;
use Wm\WmPackage\Models\FeatureCollection as FeatureCollectionModel;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivity as TaxonomyActivityModel;
use Wm\WmPackage\Models\TaxonomyPoiType as TaxonomyPoiTypeModel;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Actions\RegenerateAppPbfAction;
use Wm\WmPackage\Nova\Actions\ReindexAppScoutAction;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\AppApiLinksCard;
use Wm\WmPackage\Nova\Fields\OrderList\src\OrderList;
use Wm\WmPackage\Nova\Fields\StoreVersionField;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollItemRepeatable;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollRepeaterJsonPreset;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigHomeResolver;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigOverlaysResolver;
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;

class App extends Resource
{
    use HasFlexibleTranslatableFields;

    public static $model = ModelsApp::class;

    protected function tiptapButtons(): array
    {
        return [
            'heading',
            '|',
            'bold',
            'italic',
            'underline',
            '|',
            'bulletList',
            'orderedList',
            '|',
            'link',
            'image',
            '|',
            'textAlign',
            '|',
            'horizontalRule',
            '|',
            'editHtml',
        ];
    }

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->sortable(),

            Tab::group('Configuration', [
                Tab::make('frontend', $this->app_tab()),
                Tab::make('Release', $this->app_release_data_tab()),
                Tab::make('home', $this->home_tab()),
                Tab::make('translations', $this->translations_tab()),
                Tab::make('map', $this->map_tab()),
                Tab::make('pois', $this->pois_tab()),
                Tab::make('theme', $this->theme_tab()),
                Tab::make('pages', $this->pages_tab()),
                Tab::make('acquisition_form', $this->acquisition_form_tab()),
                Tab::make('filters', $this->filters_tab()),
                Tab::make('wordpress', $this->wordpress_tab()),
                Tab::make('languages', $this->languages_tab()),
                Tab::make('searchable', $this->searchable_tab()),
                Tab::make('analytics', $this->analytics_tab()),
                Tab::make('overlays', $this->overlays_tab()),
            ]),

            // TODO: implement fields
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        return [
            new AppApiLinksCard($request->findModelOrFail()),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            new RegenerateAppPbfAction()
                ->onlyOnDetail()
                ->confirmText(__('Are you sure you want to regenerate all PBFs for this app? This operation may take a long time.'))
                ->confirmButtonText(__('Yes, regenerate'))
                ->cancelButtonText(__('Cancel')),
            new ReindexAppScoutAction()
                ->onlyOnDetail()
                ->confirmText(__('Are you sure you want to reindex the search for this app? This operation may take a long time.'))
                ->confirmButtonText(__('Yes, reindex'))
                ->cancelButtonText(__('Cancel')),
            ExecuteEcTrackDataChainAction::make([
                fn ($track) => new UpdateEcTrackAwsJob($track),
            ], __('Update Tracks on AWS'))
                ->onlyOnDetail()
                ->confirmText(__('Are you sure you want to update all tracks of this app on AWS?'))
                ->confirmButtonText(__('Yes, update'))
                ->cancelButtonText(__('No, cancel')),
            ExecuteEcTrackDataChainAction::make()
                ->onlyOnDetail()
                ->confirmText(__('Are you sure you want to process all tracks of this app?'))
                ->confirmButtonText(__('Yes, process'))
                ->cancelButtonText(__('No, cancel')),
        ];
    }

    protected function webapp_tab(): array
    {
        return [
            Heading::make(
                <<<'HTML'
                <h2><strong>WEBAPP</strong></h2>
                HTML
            )->asHtml()->hideFromIndex(),
            Boolean::make(__('Show Auth at startup'), 'webapp_auth_show_at_startup')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Shows the authentication and registration page for users')),
        ];
    }

    protected function mobile_tab(): array
    {
        return [
            Heading::make(
                <<<'HTML'
                <h2><strong>MOBILE</strong></h2>
                HTML
            )->asHtml()->hideFromIndex(),
            Boolean::make(__('Show Auth at startup'), 'auth_show_at_startup')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Shows the authentication and registration page for users')),
            Boolean::make(__('Geolocation Record Enable'), 'geolocation_record_enable')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enables user geolocation recording on tracks')),
            Boolean::make(__('Show Download Tiles'), 'properties->show_download_tiles')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Shows the download tiles button on the map')),
        ];
    }

    protected function overlays_tab(): array
    {
        return [
            Flexible::make('config_overlays')
                ->resolver(ConfigOverlaysResolver::class)
                ->menu('flexible-search-menu')
                ->button('Aggiungi elemento')
                ->help('Configurazione degli overlay della mappa')
                ->addLayout('Titolo', 'title', $this->overlays_title_layout())
                ->addLayout('Feature Collection', 'feature_collection', $this->feature_collection_layout())
                ->confirmRemove('Sei sicuro di voler eliminare questo elemento?', 'Elimina', 'Annulla'),
        ];
    }

    protected function map_layers_rank_tab(): array
    {
        return [
            OrderList::make(__('Layer Rank'))
                ->model(Layer::class)
                ->scope('app_id', fn ($resource) => (int) $resource->id)
                ->orderColumn('rank')
                ->labelColumn('name')
                ->color(fn (Layer $layer) => $layer->getStrokeColorHex())
                ->gate('update', fn ($resource) => $resource)
                ->onlyOnDetail(),
        ];
    }

    protected function map_tab(): array
    {
        return array_merge(
            $this->map_settings_tab(),
            [
                Heading::make(
                    <<<'HTML'
                    <hr style="margin: 2rem 0 1rem; border: none; border-top: 2px solid #e5e7eb;">
                    <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0;">LAYERS RANK</h2>
                    <p style="margin-top: 0.25rem;">
                        Ordina i layer della mappa per <strong>priorità di visualizzazione</strong> nel frontend.
                        I layer in cima alla lista sono disegnati <strong>sopra</strong> gli altri e quindi più visibili;
                        quelli in fondo vengono coperti dai layer con priorità maggiore.
                    </p>
                    <p style="margin-top: 0.25rem;">
                        Trascina gli elementi per cambiare l'ordine: la modifica viene salvata automaticamente.
                    </p>
                    HTML
                )->asHtml()->onlyOnDetail(),
            ],
            $this->map_layers_rank_tab(),
        );
    }

    protected function overlays_title_layout(): array
    {
        return $this->translatableFields('Label', 'label');
    }

    protected function feature_collection_layout(): array
    {
        return [
            Select::make('Feature Collection', 'feature_collection')
                ->options(function () {
                    return FeatureCollectionModel::where('app_id', $this->model()->id)
                        ->get()
                        ->pluck('name', 'id')
                        ->all();
                })
                ->rules('required')
                ->displayUsingLabels(),
        ];
    }

    protected function analytics_tab(): array
    {
        return [
            // APP ANALYTICS
            Heading::make(
                <<<'HTML'
                <h2><strong>APP ANALYTICS</strong></h2>
                <p>Configure PostHog analytics settings for the mobile app.</p>
                HTML
            )->asHtml()->hideFromIndex(),
            Boolean::make(__('Enable Analytics'), 'properties->analytics_app_enabled')
                ->default(false)
                ->hideFromIndex()
                ->help(__('If enabled, the mobile app will use Posthog for analytics')),
            Boolean::make(__('Enable Session Recording'), 'properties->analytics_app_recording_enabled')
                ->default(false)
                ->hideFromIndex()
                ->help(__('If enabled, Posthog will video record user sessions in the mobile app')),
            Number::make(__('Recording Probability'), 'properties->analytics_app_recording_probability')
                ->step(0.1)
                ->min(0)
                ->max(1)
                ->default(0)
                ->hideFromIndex()
                ->help(__('Probability of triggering session recording in the mobile app (0 = never, 1 = always)')),

            // WEBAPP ANALYTICS
            Heading::make(
                <<<'HTML'
                <hr style="margin: 2rem 0; border: none; border-top: 2px solid #e5e7eb;">
                <h2><strong>WEBAPP ANALYTICS</strong></h2>
                <p>Configure PostHog analytics settings for the webapp.</p>
                HTML
            )->asHtml()->hideFromIndex(),
            Boolean::make(__('Enable Analytics'), 'properties->analytics_webapp_enabled')
                ->default(false)
                ->hideFromIndex()
                ->help(__('If enabled, the webapp will use Posthog for analytics')),
            Boolean::make(__('Enable Session Recording'), 'properties->analytics_webapp_recording_enabled')
                ->default(false)
                ->hideFromIndex()
                ->help(__('If enabled, Posthog will video record user sessions in the webapp')),
            Number::make(__('Recording Probability'), 'properties->analytics_webapp_recording_probability')
                ->step(0.1)
                ->min(0)
                ->max(1)
                ->default(0)
                ->hideFromIndex()
                ->help(__('Probability of triggering session recording in the webapp (0 = never, 1 = always)')),
        ];
    }

    protected function app_tab(): array
    {
        return [
            Boolean::make(__('Show Download Tracks'), 'download_track_enable')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Shows download track in GPX, KML, GEOJSON')),
            Boolean::make(__('Show Travel Mode'), 'properties->show_travel_mode')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enable the Travel Mode feature on the app')),

            Tab::make('FEwebapp', $this->webapp_tab()),
            Tab::make('FE: mobile', $this->mobile_tab()),
        ];
    }

    protected function theme_tab(): array
    {
        return [
            Text::make(__('Font Family Header'), 'properties->theme->font_family_header')
                ->hideFromIndex()
                ->help(__('Font family used for headings in the app theme')),
            Text::make(__('Font Family Content'), 'properties->theme->font_family_content')
                ->hideFromIndex()
                ->help(__('Font family used for body content in the app theme')),
            Color::make(__('Primary color'), 'properties->theme->primary_color')
                ->hideFromIndex()
                ->help(__('Primary color for the app theme (e.g. buttons, links)')),
            Color::make(__('Default feature color'), 'properties->theme->default_feature_color')
                ->hideFromIndex()
                ->help(__('Default color used for map features when no specific style is set')),
        ];
    }

    protected function pois_tab(): array
    {
        return [
            Boolean::make(__('App POIs API Layer'), 'app_pois_api_layer')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enable POIs API layer for the app')),

            Number::make(__('POI Min Radius'), 'poi_min_radius')
                ->step(0.1)
                ->default(0.5)
                ->hideFromIndex()
                ->help(__('Minimum radius for POI clustering')),

            Number::make(__('POI Max Radius'), 'poi_max_radius')
                ->step(0.1)
                ->default(1.2)
                ->hideFromIndex()
                ->help(__('Maximum radius for POI clustering')),

            Number::make(__('POI Icon Zoom'), 'poi_icon_zoom')
                ->step(0.1)
                ->default(16)
                ->hideFromIndex()
                ->help(__('Zoom level for POI icons')),

            Number::make(__('POI Icon Radius'), 'poi_icon_radius')
                ->step(0.1)
                ->default(1)
                ->hideFromIndex()
                ->help(__('Radius for POI icons')),

            Number::make(__('POI Min Zoom'), 'poi_min_zoom')
                ->step(0.1)
                ->default(5)
                ->hideFromIndex()
                ->help(__('Minimum zoom level to show POIs')),

            Number::make(__('POI Label Min Zoom'), 'poi_label_min_zoom')
                ->step(0.1)
                ->default(10.5)
                ->hideFromIndex()
                ->help(__('Minimum zoom level to show POI labels')),

            Select::make(__('POI Interaction'), 'poi_interaction')
                ->options([
                    'popup' => __('Popup'),
                    'modal' => __('Modal'),
                    'none' => __('None'),
                ])
                ->default('popup')
                ->hideFromIndex()
                ->help(__('Type of interaction when clicking on POIs')),
        ];
    }

    protected function translations_tab(): array
    {
        return [
            Code::make(__('Italian Translations'), 'translations_it')
                ->language('json')
                ->rules('nullable', 'json')
                ->hideFromIndex()
                ->help(__('Enter the Italian translations in JSON format here')),
            Code::make(__('English Translations'), 'translations_en')
                ->language('json')
                ->rules('nullable', 'json')
                ->hideFromIndex()
                ->help(__('Enter the English translations in JSON format here')),
        ];
    }

    protected function pages_tab(): array
    {
        return [
            NovaTabTranslatable::make([
                Tiptap::make('Page Project', 'page_project')
                    ->buttons($this->tiptapButtons())
                    ->headingLevels([2, 3, 4]),
                Tiptap::make('Page Disclaimer', 'page_disclaimer')
                    ->buttons($this->tiptapButtons())
                    ->headingLevels([2, 3, 4]),
                Tiptap::make('Page Credits', 'page_credits')
                    ->buttons($this->tiptapButtons())
                    ->headingLevels([2, 3, 4]),
                Tiptap::make('Page Privacy', 'page_privacy')
                    ->buttons($this->tiptapButtons())
                    ->headingLevels([2, 3, 4]),
            ]),
        ];
    }

    protected function languages_tab(): array
    {
        $availableLanguages = is_null($this->model()->available_languages) ? [] : json_decode($this->model()->available_languages, true);
        $languages = Config::get('wm-app-languages.languages', []);

        return [
            Select::make(__('Default Language'), 'default_language')
                ->hideFromIndex()
                ->options($languages)
                ->displayUsingLabels()
                ->help(__('This is the default language displayed by the app.')),
            Multiselect::make(__('Available Languages'), 'available_languages')
                ->hideFromIndex()
                ->options($languages, $availableLanguages)
                ->help(__('Select languages for app translations')),
        ];
    }

    protected function searchable_tab(): array
    {
        $track_selected = is_null($this->model()->track_searchables) ? [] : json_decode($this->model()->track_searchables, true);
        $poi_selected = is_null($this->model()->poi_searchables) ? [] : json_decode($this->model()->poi_searchables, true);

        return [
            Multiselect::make(__('Track Search In'), 'track_searchables')
                ->options([
                    'name' => 'Name',
                    'description' => 'Description',
                    'excerpt' => 'Excerpt',
                    'ref' => 'REF',
                    'osmid' => 'OSMID',
                    'taxonomyThemes' => 'Themes',
                    'taxonomyWheres' => 'Wheres',
                    'taxonomyActivities' => 'Activity',
                ], $track_selected)
                ->help(__('Select one or more criteria from "name", "description", "excerpt", "ref", "osmid", "taxonomy themes", "taxonomy activity"')),
            Multiselect::make(__('POI Search In'), 'poi_searchables'),

        ];
    }

    protected function home_tab(): array
    {
        return [
            Images::make('Home Images', 'home_images'),
            Boolean::make(__('Show searchbar'), 'show_search')
                ->default(true)
                ->hideFromIndex()
                ->help(__('Activate to show the search bar on the home')),
            NovaTabTranslatable::make([
                Code::make('Welcome', 'welcome'),
                /*   Tiptap::make('Welcome', 'welcome')
                    ->buttons([
                        'heading',
                        '|',
                        'bold',
                        'italic',
                        'underline',
                        '|',
                        'bulletList',
                        'orderedList',
                        '|',
                        'link',
                        'image',
                        '|',
                        'textAlign',
                        '|',
                        'horizontalRule',
                        '|',
                        'editHtml',
                    ])
                    ->headingLevels([2, 3, 4]),*/
            ]),
            Flexible::make('config_home')
                ->resolver(ConfigHomeResolver::class)
                ->menu('flexible-search-menu')
                ->button('Aggiungi contenuto')
                ->help("Configurazione della home page dell'app")
                ->addLayout('Titolo', 'title', $this->title_layout())
                ->addLayout(__('Horizontal Scroll Activities'), 'horizontal_scroll_activities', $this->horizontal_scroll_activities_layout())
                ->addLayout(__('Horizontal Scroll POI Types'), 'horizontal_scroll_poi_types', $this->horizontal_scroll_poi_types_layout())
                ->addLayout('Layer', 'layer', $this->layer_layout())
                ->addLayout('Slug', 'slug', $this->slug_layout())
                ->addLayout('External URL', 'external_url', $this->external_url_layout())
                ->confirmRemove('Sei sicuro di voler eliminare questo elemento?', 'Elimina', 'Annulla'),
        ];
    }

    protected function app_release_data_tab(): array
    {
        return [
            StoreVersionField::make(),

            Text::make(__('Name'), 'name')
                ->sortable()
                ->required()
                ->help(__('App name on the stores (App Store and Playstore).')),
            Text::make(__('Sku'), 'sku')
                ->required()
                ->help(__('App name on the stores (App Store and Playstore).')),
            Text::make(__('Play Store link (android)'), 'android_store_link')
                ->hideFromIndex()
                ->help(__('Link to the app on the Play Store')),
            Text::make(__('App Store link (iOS)'), 'ios_store_link')
                ->hideFromIndex()
                ->help(__('Link to the app on the App Store')),
            Images::make(__('Icon'), 'icon')
                // ->rules('image', 'mimes:png', 'dimensions:width=1024,height=1024')
                ->help(__('Required size is :widthx:heightpx', ['width' => 1024, 'height' => 1024]))
                ->hideFromIndex(),
            Images::make(__('Splash image'), 'splash')
                // ->rules('image', 'mimes:png', 'dimensions:width=2732,height=2732')
                ->help(__('Required size is :widthx:heightpx', ['width' => 2732, 'height' => 2732]))
                ->hideFromIndex(),
            Images::make(__('Icon small'), 'icon_small')
                // ->rules('image', 'mimes:png', 'dimensions:width=512,height=512')
                ->help(__('Required size is :widthx:heightpx', ['width' => 512, 'height' => 512]))
                ->hideFromIndex(),
            Boolean::make(__('Force to Release Update'), 'properties->force_to_release_update')
                ->default(false)
                ->hideFromIndex()
                ->help(__('If enabled, the app will check for updates and show a popup when a new version is available.')),
        ];
    }

    protected function acquisition_form_tab(): array
    {
        return [
            Code::Make(__('POI acquisition forms'), 'poi_acquisition_form')
                ->language('json')
                ->rules('json')
                ->default($this->getDefaultPoiForm())
                ->help(__('This JSON structures the acquisition form for UGC POIs. Knowledge of JSON format required.').view('wm-package::poi-forms')->render()),
            Code::Make(__('TRACK acquisition forms'), 'track_acquisition_form')
                ->language('json')
                ->rules('json')
                ->default($this->getDefaultTrackForm())
                ->help(__('This JSON structures the acquisition form for UGC Tracks. Knowledge of JSON format required.').view('wm-package::track-forms')->render()),
        ];
    }

    protected function filters_tab(): array
    {
        return [
            Heading::make(
                <<<'HTML'
                <h2><strong>FILTERS ACTIVATION</strong></h2>
                HTML
            )->asHtml()->hideFromIndex(),
            Boolean::make(__('Activity Filter'), 'filter_activity')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Activates the activity filter for tracks')),
            Boolean::make(__('Layer Filter'), 'filter_layer')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Activates the layer-based filter for tracks and data')),
            Boolean::make(__('POI Type Filter'), 'filter_poi_type')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Activates the POI type filter for points of interest')),
            Boolean::make(__('Track Duration Filter'), 'filter_track_duration')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enables filtering of tracks by duration')),
            Boolean::make(__('Track Distance Filter'), 'filter_track_distance')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enables filtering of tracks by distance')),
            Boolean::make(__('Track Difficulty Filter'), 'filter_track_difficulty')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enables filtering of tracks by difficulty level')),
            Heading::make(
                <<<'HTML'
                <h2><strong>FILTERS LABELS</strong></h2>
                <br/>
                <ul>
                    <li><p><strong>Activity Filter Label</strong>: Text to be displayed for the Activity filter.</p></li>
                    <li><p><strong>Layer Filter Label</strong>: Text to be displayed for the Layer filter.</p></li>
                    <li><p><strong>Poi Type Filter Label</strong>: Text to be displayed for the Poi Type filter.</p></li>
                    <li><p><strong>Duration Filter Label</strong>: Text to be displayed for the tracks duration filter.</p></li>
                    <li><p><strong>Distance Filter Label</strong>: Text to be displayed for the tracks distance filter.</p></li>
                </ul>
                HTML
            )->asHtml()->hideFromIndex(),
            NovaTabTranslatable::make([
                Text::make(__('Activity Filter Label'), 'filter_activity_label'),
                Text::make(__('Layer Filter Label'), 'filter_layer_label'),
                Text::make(__('Poi Type Filter Label'), 'filter_poi_type_label'),
                Text::make(__('Duration Filter Label'), 'filter_track_duration_label'),
                Text::make(__('Distance Filter Label'), 'filter_track_distance_label'),
            ])->hideFromIndex(),

            Text::make(__('Activity Exclude Filter'), 'filter_activity_exclude')
                ->hideFromIndex()
                ->help(__('Insert the activities you want to exclude from the filter, separated by commas')),
            Multiselect::make(__('Filterable layers'), 'filter_layers_ids')
                ->onlyOnForms()
                ->options(function () {
                    $app = $this->model();

                    return $app->layers()
                        ->orderBy('id')
                        ->get()
                        ->mapWithKeys(function ($layer) {
                            $title = $layer->getStringName() ?: ('Layer #'.$layer->id);

                            return [$layer->id => $title];
                        })
                        ->toArray();
                })
                ->resolveUsing(function () {
                    return $this->model()
                        ->filterLayers()
                        ->pluck('layers.id')
                        ->all();
                })
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $ids = $request->input($requestAttribute, []);
                    $model->filterLayers()->sync($ids ?? []);
                })
                ->help(__('Select which layers will be available as filters')),
            Text::make(__('Poi Type Exclude Filter'), 'filter_poi_type_exclude')
                ->hideFromIndex()
                ->help(__('Insert the poi types you want to exclude from the filter, separated by commas')),
            Number::make(__('Track Min Duration Filter'), 'filter_track_duration_min')
                ->hideFromIndex()
                ->help(__('Set the minimum duration of the duration filter')),
            Number::make(__('Track Max Duration Filter'), 'filter_track_duration_max')
                ->hideFromIndex()
                ->help(__('Set the maximum duration of the duration filter')),
            Number::make(__('Track Duration Steps Filter'), 'filter_track_duration_steps')
                ->hideFromIndex()
                ->help(__('Set the steps of the duration filter')),
            Number::make(__('Track Min Distance Filter'), 'filter_track_distance_min')
                ->hideFromIndex()
                ->help(__('Set the minimum distance of the distance filter')),
            Number::make(__('Track Max Distance Filter'), 'filter_track_distance_max')
                ->hideFromIndex()
                ->help(__('Set the maximum distance of the distance filter')),
            Number::make(__('Track Distance Step Filter'), 'filter_track_distance_steps')
                ->hideFromIndex()
                ->help(__('Set the steps of the distance filter')),
        ];
    }

    protected function wordpress_tab(): array
    {
        $title = __('WordPress Configuration');
        $description = __('Configuration for WordPress (wm-package) integration. These values are included in the app config.json.');
        $heading = <<<HTML
            <h2><strong>{$title}</strong></h2>
            <p>{$description}</p>
            HTML;

        return [
            Heading::make($heading)
                ->asHtml()
                ->hideFromIndex(),
            Boolean::make(__('Download Track Enable'), 'properties->wp_download_track_enable')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enables track download on the WordPress site')),
            Boolean::make(__('Generate Edges'), 'properties->wp_generate_edges')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Generates edges for tracks')),
            Boolean::make(__('Show Distance'), 'properties->wp_show_distance')
                ->default(true)
                ->hideFromIndex()
                ->help(__('Shows the distance')),
            Boolean::make(__('Show Duration Backward'), 'properties->wp_show_duration_backward')
                ->default(true)
                ->hideFromIndex()
                ->help(__('Shows duration in backward direction')),
            Boolean::make(__('Show Duration Forward'), 'properties->wp_show_duration_forward')
                ->default(true)
                ->hideFromIndex()
                ->help(__('Shows duration in forward direction')),
            Boolean::make(__('Show Ascent'), 'properties->wp_show_ascent')
                ->default(true)
                ->hideFromIndex()
                ->help(__('Shows ascent elevation gain')),
            Boolean::make(__('Show Descent'), 'properties->wp_show_descent')
                ->default(true)
                ->hideFromIndex()
                ->help(__('Shows descent elevation loss')),
            Boolean::make(__('Show Ele To'), 'properties->wp_show_ele_to')
                ->default(true)
                ->hideFromIndex()
                ->help(__('Shows arrival elevation')),
            Boolean::make(__('Show Ele From'), 'properties->wp_show_ele_from')
                ->default(true)
                ->hideFromIndex()
                ->help(__('Shows start elevation')),
            Color::make(__('Primary Color'), 'properties->wp_primary')
                ->default('#de1b0d')
                ->hideFromIndex()
                ->help(__('Primary color for the WordPress theme (e.g. buttons, links)')),
        ];
    }

    protected function getDefaultPoiForm(): string
    {
        $form = Config::get('wm-acquisiotion-form-default.poi');

        return json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    protected function getDefaultTrackForm(): string
    {
        $form = Config::get('wm-acquisiotion-form-default.track');

        return json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    protected function title_layout(): array
    {
        return $this->config_home_title_layout();
    }

    protected function horizontal_scroll_activities_layout(): array
    {
        $fields = $this->config_home_title_layout();
        $fields[] = $this->horizontalScrollItemsRepeater(
            HorizontalScrollItemRepeatable::make($this->horizontalScrollActivityOptions())
        );

        return $fields;
    }

    protected function horizontal_scroll_poi_types_layout(): array
    {
        $fields = $this->config_home_title_layout();
        $fields[] = $this->horizontalScrollItemsRepeater(
            HorizontalScrollItemRepeatable::make($this->horizontalScrollPoiTypeOptions())
        );

        return $fields;
    }

    /**
     * JSON preset for config_home horizontal scroll layouts: reliable hydration of `items` from the Whitecube layout.
     */
    protected function horizontalScrollItemsRepeater(HorizontalScrollItemRepeatable $repeatable): Repeater
    {
        return Repeater::make(__('Items'), 'items')
            ->repeatables([$repeatable])
            ->preset(new HorizontalScrollRepeaterJsonPreset)
            ->rules('required', 'array')
            ->help(__('Add one or more items for this horizontal scroll.'));
    }

    protected function horizontalScrollActivityOptions(): array
    {
        return TaxonomyActivityModel::query()
            ->get(['identifier', 'name'])
            ->mapWithKeys(function (TaxonomyActivityModel $activity) {
                return [
                    $activity->identifier => $this->getTaxonomyLabel($activity->name, $activity->identifier),
                ];
            })
            ->sort()
            ->toArray();
    }

    protected function horizontalScrollPoiTypeOptions(): array
    {
        return TaxonomyPoiTypeModel::query()
            ->get(['identifier', 'name'])
            ->mapWithKeys(function (TaxonomyPoiTypeModel $poiType) {
                $identifier = 'poi_type_'.$poiType->identifier;

                return [
                    $identifier => $this->getTaxonomyLabel($poiType->name, $identifier),
                ];
            })
            ->sort()
            ->toArray();
    }

    protected function getTaxonomyLabel($name, ?string $fallback): string
    {
        if (is_array($name)) {
            return $name['it'] ?? $name['en'] ?? (string) $fallback;
        }

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return (string) $fallback;
    }

    protected function slug_layout(): array
    {
        return array_merge($this->config_home_title_layout(), [
            Text::make('Slug', 'slug')
                ->rules('required')
                ->resolveUsing(function ($value) {
                    return $value ?: 'project';
                }),
            Text::make('Image url', 'image_url'), // TODO: fare in modo di usare Media caricando un immagine e restituendo l'url
        ]);
    }

    protected function external_url_layout(): array
    {
        return array_merge($this->config_home_title_layout(), [
            Text::make('Url', 'url')->rules('required'),
            Text::make('Image url', 'image_url'), // TODO: fare in modo di usare Media caricando un immagine e restituendo l'url
        ]);
    }

    protected function config_home_title_layout(): array
    {
        return $this->translatableFields('Title', 'title', required: true);
    }

    protected function base_layout(): array
    {
        return [
            Text::make('Title', 'title')->rules('required'),
            Text::make('Url', 'url')->rules('required'),
            // TODO: Items è un array, dove ogni elemento ha un titolo un image_url e un track_id (o poi_id)
        ];
    }

    protected function layer_layout(): array
    {
        return [
            Select::make('Layer', 'layer')
                ->options(function () {
                    $layers = Layer::where('app_id', $this->model()->id)
                        ->get()
                        ->map(function ($layer) {
                            // Accesso al titolo translatable in modo più pulito
                            $title = $layer->getStringName();
                            if (is_array($title)) {
                                // Se è un array, prendi prima la versione italiana, poi quella inglese, altrimenti usa l'ID
                                $title = $title['it'] ?? $title['en'] ?? ('Layer #'.$layer->id);
                            } elseif (is_null($title)) {
                                $title = 'Layer #'.$layer->id;
                            }

                            return [
                                'id' => $layer->id,
                                'title' => $title,
                            ];
                        });
                    $layers = $layers->sortBy('title');

                    return $layers->pluck('title', 'id')->all();
                })
                ->searchable()
                ->rules('required')
                ->help(__('Select an existing layer'))
                ->displayUsingLabels(),
        ];
    }

    protected function map_settings_tab(): array
    {
        $selectedTileLayers = is_null($this->model()->tiles) ? [] : json_decode($this->model()->tiles, true);
        $appTiles = new AppTiles;
        $t = $appTiles->oldval();

        return [
            // --- TILES ---
            Heading::make(
                <<<'HTML'
                <p><strong>Tiles Label</strong>: Text displayed for selecting tiles through the app.</p>
                HTML
            )->asHtml()->hideFromIndex(),
            NovaTabTranslatable::make([
                Text::make('Tiles Label'),
            ])->hideFromIndex(),
            Multiselect::make(__('Tiles'), 'tiles')
                ->options($t, $selectedTileLayers)
                ->reorderable()
                ->hideFromIndex()
                ->help(__('Select which tile layers the app will use; order follows insertion order. The first tile type in the list is used to download tiles for offline mode in the frontend.')),

            // --- DATA CONTROLS ---
            Heading::make(
                <<<'HTML'
                <ul>
                  <li><p><strong>Data Label</strong>: Text to be displayed as the header of the data filter.</p></li>
                  <li><p><strong>Pois Data Label</strong>: Text to be displayed for the POIs filter.</p></li>
                  <li><p><strong>Tracks Data Label</strong>: Text to be displayed for the Tracks filter.</p></li>
                </ul>
                HTML
            )->asHtml()->hideFromIndex(),
            NovaTabTranslatable::make([
                Text::make('Data Label')->help(__('Text to be displayed as the header of the data filter.')),
                Text::make('Pois Data Label'),
                Text::make('Tracks Data Label'),
            ])->hideFromIndex(),
            Boolean::make('Show POIs data by default', 'pois_data_default')
                ->hideFromIndex()
                ->help(__('Turn this option off if you do not want to show POIs by default on the map.')),
            Text::make('POI Data Icon', 'pois_data_icon', function () {
                return '<div style="width:64px;height:64px;">'.$this->pois_data_icon.'</div>';
            })->asHtml()->onlyOnDetail(),
            Textarea::make('POI Data Icon SVG', 'pois_data_icon')
                ->onlyOnForms()
                ->help(__('SVG icon shown in the filter for POIs')),
            Boolean::make('Show Tracks data by default', 'tracks_data_default')
                ->hideFromIndex()
                ->help(__('Turn this option off if you do not want to show all track layers by default on the map')),
            Text::make('Track Data Icon', 'tracks_data_icon', function () {
                return '<div style="width:64px;height:64px;">'.$this->tracks_data_icon.'</div>';
            })->asHtml()->onlyOnDetail(),
            Textarea::make('Track Data Icon SVG', 'tracks_data_icon')
                ->onlyOnForms()
                ->help(__('SVG icon shown in the filter for Tracks')),

            // --- ZOOM & STROKE ---
            Heading::make(
                <<<'HTML'
                <p><strong>Map zoom and stroke settings.</strong></p>
                HTML
            )->asHtml()->hideFromIndex(),
            Number::make(__('Def Zoom'), 'map_def_zoom')
                ->min(1)->max(19)->step(0.1)->default(12)
                ->hideFromIndex()
                ->help(__('The default zoom level when the map is first loaded.')),
            Number::make(__('Max Zoom'), 'map_max_zoom')
                ->min(1)->max(20)->default(16)
                ->rules([
                    function (string $attribute, mixed $value, \Closure $fail) {
                        $min = request()->input('map_min_zoom');
                        if ($min === null || $min === '') {
                            return;
                        }

                        if ((float) $value < (float) $min) {
                            $fail(__('Max Zoom must be greater than or equal to Min Zoom.'));
                        }
                    },
                ])
                ->hideFromIndex()
                ->help(__('Maximum zoom level for the map')),
            Number::make(__('Min Zoom'), 'map_min_zoom')
                ->min(1)->max(20)->default(12)
                ->rules([
                    function (string $attribute, mixed $value, \Closure $fail) {
                        $max = request()->input('map_max_zoom');
                        if ($max === null || $max === '') {
                            return;
                        }

                        if ((float) $value > (float) $max) {
                            $fail(__('Min Zoom must be less than or equal to Max Zoom.'));
                        }
                    },
                ])
                ->hideFromIndex()
                ->help(__('Minimum zoom level for the map')),
            Number::make(__('Max Stroke width'), 'map_max_stroke_width')
                ->min(0)->max(19)->default(6)
                ->hideFromIndex()
                ->help(__('Set max stroke width of line string, applied at max zoom level')),
            Number::make(__('Min Stroke width'), 'map_min_stroke_width')
                ->min(0)->max(19)->default(3)
                ->hideFromIndex()
                ->help(__('Set min stroke width of line string, applied at min zoom level')),

            // --- BBOX ---
            Text::make(__('Bounding BOX'), 'map_bbox')
                ->nullable()
                ->hideFromIndex()
                ->rules([
                    function ($attribute, $value, $fail) {
                        if ($value === null || $value === '') {
                            return;
                        }
                        $decoded = json_decode($value);
                        if (! is_array($decoded)) {
                            $fail('The '.$attribute.' is invalid. Follow the example [9.9456,43.9116,11.3524,45.0186]');
                        }
                    },
                ])
                ->help(__('Bounding the map view. Example: [9.9456,43.9116,11.3524,45.0186]')),

            // --- ADVANCED MAP SETTINGS ---
            Heading::make(
                <<<'HTML'
                <p><strong>Advanced map display settings.</strong></p>
                HTML
            )->asHtml()->hideFromIndex(),
            Boolean::make(__('Start/End Icons Show'), 'start_end_icons_show')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Displays start and end icons on the map')),
            Number::make(__('start_end_icons_min_zoom'))
                ->min(10)->max(20)
                ->hideFromIndex()
                ->help(__('Set minimum zoom at which start and end icons are shown in general maps (start_end_icons_show must be true)')),
            Boolean::make(__('Ref on Track Show'), 'ref_on_track_show')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Displays reference labels on tracks')),
            Number::make(__('ref_on_track_min_zoom'))
                ->min(10)->max(20)
                ->hideFromIndex()
                ->help(__('Set minimum zoom at which ref parameter is shown on tracks line in general maps (ref_on_track_show must be true)')),
            Boolean::make(__('Show Features In Viewport'), 'properties->show_features_in_viewport')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enable the Features In Viewport on the app')),
            Number::make(__('Min Zoom Features In Viewport'), 'properties->min_zoom_features_in_viewport')
                ->min(1)
                ->max(20)
                ->default(10)
                ->hideFromIndex()
                ->help(__('Minimum zoom level for enabling Features In Viewport')),
            Number::make(__('Max Zoom Features In Viewport'), 'properties->max_zoom_features_in_viewport')
                ->min(1)
                ->max(20)
                ->default(12)
                ->hideFromIndex()
                ->help(__('Maximum zoom level for enabling Features In Viewport')),
            Boolean::make(__('Show Track Direction Arrow'), 'properties->show_track_direction_arrow')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enables the track direction arrow in the map.')),
            Number::make(__('alert_poi_radius'))
                ->default(100)
                ->hideFromIndex()
                ->help(__('Set the radius (in meters) of the activation circle with the center as the user position. The nearest POI inside the circle triggers the alert')),
            Number::make(__('flow_line_quote_orange'))
                ->default(800)
                ->hideFromIndex()
                ->help(__('Defines the elevation by which the track turns orange')),
            Number::make(__('flow_line_quote_red'))
                ->default(1500)
                ->hideFromIndex()
                ->help(__('Defines the elevation by which the track turns red')),

            // --- GPS ---
            Select::make(__('GPS Accuracy Default'), 'gps_accuracy_default')
                ->options([
                    '5' => '5 meters',
                    '10' => '10 meters',
                    '20' => '20 meters',
                    '100' => '100 meters',
                ])
                ->hideFromIndex()
                ->help(__('Set the default GPS accuracy level for tracking.'))
                ->displayUsingLabels(),
        ];
    }
}
