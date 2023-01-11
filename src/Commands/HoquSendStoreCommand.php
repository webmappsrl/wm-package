<?php

namespace Wm\WmPackage\Commands;

use Exception;
use Illuminate\Console\Command;
use Wm\WmPackage\Http\HoquClient;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Wm\WmPackage\Model\HoquCallerJob;

class HoquSendStoreCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoqu:store
    {--class= : required, the class that will execute job on processor}
    {--featureId= : required, the feature id to update after completed job}
    {--field= : required, the field to update after completed job}
    {--input= : required, the input to send to processor}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Performs a call to Hoqu to store a job, saves a new HoquCallerModel in the database with the hoqu response';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(HoquClient $hoquClient)
    {
        $class = $this->option('class');
        $input = $this->option('input');
        $field = $this->option('field');
        $featureId = $this->option('featureId');

        $validator = Validator::make([
            'class' => $class,
            'input' => $input,
            'field' => $field,
            'featureId' => $featureId,
        ], [
            //TODO: add more validation rules
            'class' => ['required'],
            'input' => ['required'],
            'field' => ['required'],
            'featureId' => ['required'],

        ]);

        if ($validator->fails()) {
            $this->info('Something goes wrong during command option validation. See error messages below:');

            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return Command::INVALID;
        }

        $this->info($class);
        $this->info($input);
        /**
         * Send store authenticated request to hoqu
         */
        $response = $hoquClient->store([
            'name' => $class,
            'input' => $input
        ]);

        try {
            HoquCallerJob::create([
                'job_id' => $response['job_id'],
                'class' => $class,
                'feature_id' => $featureId,
                'field_to_update' => $field,
            ]);
        } catch (Throwable | Exception $e) {
            $this->error(print_r($response, true));
            throw $e;
        }




        return Command::SUCCESS;
    }
}
