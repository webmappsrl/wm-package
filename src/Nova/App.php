<?php

namespace Wm\WmPackage\Nova;

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Support\Facades\Config;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Laravel\Nova\Tabs\Tab;
use Marshmallow\Tiptap\Tiptap;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Actions\UpdateTracksOnAws;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigHomeResolver;

class App extends Resource
{
    public static $model = \Wm\WmPackage\Models\App::class;

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
            Tab::group('App', [
                Tab::make('home', $this->home_tab()),
                Tab::make('webapp', $this->webapp_tab()),
                Tab::make('app', $this->app_tab()),
                Tab::make('pois', $this->pois_tab()),
                Tab::make('release_data', $this->app_release_data_tab()),
                Tab::make('pages', $this->pages_tab()),
                Tab::make('acquisition_form', $this->acquisition_form_tab()),
                Tab::make('filters', $this->filters_tab()),
            ]),

            // TODO: implement fields
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            UpdateTracksOnAws::make()
                ->onlyOnDetail()
                ->confirmText('Sei sicuro di voler aggiornare tutte le tracks di questa app su AWS?')
                ->confirmButtonText('Sì, aggiorna')
                ->cancelButtonText('No, annulla'),
        ];
    }

    protected function webapp_tab(): array
    {
        return [
            Boolean::make(__('Show Auth at startup'), 'webapp_auth_show_at_startup')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Shows the authentication and registration page for users')),

        ];
    }

    protected function app_tab(): array
    {
        return [
            Boolean::make(__('Show Auth at startup'), 'auth_show_at_startup')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Shows the authentication and registration page for users')),
            Boolean::make(__('Geolocation Record Enable'), 'geolocation_record_enable')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enables user geolocation recording on tracks')),

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

    protected function pages_tab(): array
    {
        return [
            NovaTabTranslatable::make([
                Tiptap::make('Page Project', 'page_project'),
                Tiptap::make('Page Disclaimer', 'page_disclaimer'),
                Tiptap::make('Page Credits', 'page_credits'),
                Tiptap::make('Page Privacy', 'page_privacy'),
            ]),
        ];
    }

    protected function home_tab(): array
    {
        return [
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
                ->addLayout('Layer', 'layer', $this->layer_layout())
                ->addLayout('Slug', 'slug', $this->slug_layout())
                ->addLayout('External URL', 'external_url', $this->external_url_layout())
                ->confirmRemove('Sei sicuro di voler eliminare questo elemento?', 'Elimina', 'Annulla'),
        ];
    }

    protected function app_release_data_tab(): array
    {
        return [
            Text::make(__('Name'), 'name')
                ->sortable()
                ->required()
                ->help(__('App name on the stores (App Store and Playstore).')),
            Text::make(__('Sku'), 'sku')
                ->required()
                ->help(__('App name on the stores (App Store and Playstore).')),
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
                    <li><p><strong>Theme Filter Label</strong>: Text to be displayed for the Theme filter.</p></li>
                    <li><p><strong>Poi Type Filter Label</strong>: Text to be displayed for the Poi Type filter.</p></li>
                    <li><p><strong>Duration Filter Label</strong>: Text to be displayed for the tracks duration filter.</p></li>
                    <li><p><strong>Distance Filter Label</strong>: Text to be displayed for the tracks distance filter.</p></li>
                </ul>
                HTML
            )->asHtml()->hideFromIndex(),
            NovaTabTranslatable::make([
                Text::make('Activity Filter Label', 'filter_activity_label'),
                Text::make('Theme Filter Label', 'filter_theme_label'),
                Text::make('Poi Type Filter Label', 'filter_poi_type_label'),
                Text::make('Duration Filter Label', 'filter_track_duration_label'),
                Text::make('Distance Filter Label', 'filter_track_distance_label'),
            ])->hideFromIndex(),

            Text::make('Activity Exclude Filter', 'filter_activity_exclude')
                ->hideFromIndex()
                ->help(__('Insert the activities you want to exclude from the filter, separated by commas')),
            Text::make('Theme Exclude Filter', 'filter_theme_exclude')
                ->hideFromIndex()
                ->help(__('Insert the themes you want to exclude from the filter, separated by commas')),
            Text::make('Poi Type Exclude Filter', 'filter_poi_type_exclude')
                ->hideFromIndex()
                ->help(__('Insert the poi types you want to exclude from the filter, separated by commas')),
            Number::make('Track Min Duration Filter', 'filter_track_duration_min')
                ->hideFromIndex()
                ->help(__('Set the minimum duration of the duration filter')),
            Number::make('Track Max Duration Filter', 'filter_track_duration_max')
                ->hideFromIndex()
                ->help(__('Set the maximum duration of the duration filter')),
            Number::make('Track Duration Steps Filter', 'filter_track_duration_steps')
                ->hideFromIndex()
                ->help(__('Set the steps of the duration filter')),
            Number::make('Track Min Distance Filter', 'filter_track_distance_min')
                ->hideFromIndex()
                ->help(__('Set the minimum distance of the distance filter')),
            Number::make('Track Max Distance Filter', 'filter_track_distance_max')
                ->hideFromIndex()
                ->help(__('Set the maximum distance of the distance filter')),
            Number::make('Track Distance Step Filter', 'filter_track_distance_steps')
                ->hideFromIndex()
                ->help(__('Set the steps of the distance filter')),
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
        return [
            Text::make('Titolo', 'title')->rules('required'),
        ];
    }

    protected function slug_layout(): array
    {
        return [
            Text::make('Title', 'title'),
            Text::make('Slug', 'slug')
                ->rules('required')
                ->resolveUsing(function ($value) {
                    return $value ?: 'project';
                }),
            Text::make('Image url', 'image_url'), // TODO: fare in modo di usare Media caricando un immagine e restituendo l'url
        ];
    }

    protected function external_url_layout(): array
    {
        return [
            Text::make('Title', 'title'),
            Text::make('Url', 'url')->rules('required'),
            Text::make('Image url', 'image_url'), // TODO: fare in modo di usare Media caricando un immagine e restituendo l'url
        ];
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
                ->help(__('Seleziona un layer esistente'))
                ->displayUsingLabels(),
        ];
    }
}
