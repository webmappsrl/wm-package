<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\PBFGeneratorService;

/**
 * MBTILES Specs documentation: https://github.com/mapbox/mbtiles-spec/blob/master/1.3/spec.md
 *
 * ATTENTION!!!!!
 *
 * For this command to work first the EcTracks should have the following columns calculated:
 * - layers
 * - themes
 * - activities
 * - searchable
 * This is done by following command:
 *
 * And also the geometry of all EcTracks should have been transformed to EPSG:4326 ('UPDATE ec_tracks SET geometry = ST_SetSRID(geometry, 4326);')
 */
class WmGeneratePBFCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbf:generate {app_id} {--min= : custom min_zoom} {--max= : custom max_zoom} {--no_pbf_layer : do not generate pbf layer} {--optimized : use optimized bottom-up approach with clustering} {--test-tracks : test track retrieval and bottom-up approach without generating PBF} {--test-generate : test the generate method directly with specific parameters}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create PBF files for the app and upload the to AWS.';

    protected $min_zoom;

    protected $max_zoom;

    protected $app_id;

    protected $no_pbf_layer = false;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $app = App::where('id', $this->argument('app_id'))->first();
        if (! $app) {
            $this->error('App with id '.$this->argument('app_id').' not found!');

            return;
        }

        $optimized = $this->option('optimized');

        if ($optimized) {
            $this->info('🚀 Utilizzo approccio ottimizzato con clustering geografico');

            PBFGeneratorService::make()->generateWholeAppPbfsOptimized(
                $app,
                $this->option('min'),
                $this->option('max'),
                $this->no_pbf_layer
            );
        } else {
            $this->info('🔄 Utilizzo approccio tradizionale');

            PBFGeneratorService::make()->generateWholeAppPbfs(
                $app,
                $this->option('min'),
                $this->option('max'),
                $this->no_pbf_layer
            );
        }

        $this->output->success('PBF generation process started!');

        return 0;
    }
}
