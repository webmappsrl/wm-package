<?php

namespace Wm\WmPackage\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Wm\WmPackage\Http\HoquClient;
use Wm\WmPackage\Model\HoquCallerJob;
use Wm\WmPackage\Services\JobsPipelineHandler;

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
    public function handle(JobsPipelineHandler $jobsService)
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


        $jobsService->createCallerStoreJobsPipeline($class, $input, $featureId, $field);


        return Command::SUCCESS;
    }
}
