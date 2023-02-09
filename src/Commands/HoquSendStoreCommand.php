<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
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
    {--input= : required, the input to send to processor}
    {--model= : required, the model to update}';

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
        //TODO: add verbosity to command
        //TODO: handle a log

        $class = $this->option('class');
        $input = $this->option('input');
        $field = $this->option('field');
        $featureId = $this->option('featureId');
        $modelNamespace = $this->option('model');

        $validator = Validator::make([
            'class' => $class,
            'input' => $input,
            'field' => $field,
            'featureId' => $featureId,
            'model' => $modelNamespace,
        ], [
            //TODO: add more validation rules
            'class' => ['required'],
            'input' => ['required'],
            'field' => ['required'],
            'featureId' => ['required'],
            'model' => ['required'], //TODO: check model existence

        ]);

        if ($validator->fails()) {
            $this->info('Something goes wrong during command option validation. See error messages below:');

            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return Command::INVALID;
        }

        //retrieve the model by class namespace and id
        //the model MUST exists
        $model = $modelNamespace::find($featureId);

        //Send a STORE request to hoqu, then create a job with status progress on this instance
        $jobsService->createCallerStoreJobsPipeline($class, $input, $field, $model);

        $this->info('STORE pipeline successfully started');

        return Command::SUCCESS;
    }
}
