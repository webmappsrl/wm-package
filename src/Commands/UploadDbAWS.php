<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\DbDumper\Exceptions\CannotStartDump;
use Spatie\DbDumper\Exceptions\DumpFailed;

class UploadDbAWS extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:upload_db_aws
                            {dumpname? : the name of the sql zip file to upload}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Uploads the given sql file and the last-dump of the database to AWS';

    protected $appName;

    protected $dumpName;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->appName = config('app.name');
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            if (config('app.env') !== 'production') {
                $this->log('You can upload dumps to AWS only from a Production instance!');

                return 0;
            }

            $this->log('db:upload_db_aws -> is started');
            $wmdumps = Storage::disk('wmdumps');
            if (! $wmdumps) {
                Log::error('db:upload_db_aws -> wmdumps disk not found');
                throw new Exception('db:upload_db_aws -> wmdumps disk not found');
            }
            $local = Storage::disk('backups');

            if (! $local) {
                Log::error('db:upload_db_aws -> local disk not found');
                throw new Exception('db:upload_db_aws -> local disk not found');
            }

            if ($this->argument('dumpname')) {
                $this->dumpName = $this->argument('dumpname');
                $lastLocalDump = $local->get($this->dumpName);
                $this->log('db:upload_db_aws -> START upload to aws');
                $wmdumps->put('maphub/'.$this->appName.'/'.$this->dumpName.'.gz', $lastLocalDump);
                $this->log('db:upload_db_aws -> DONE upload to aws');
            }

            $this->log('db:upload_db_aws -> START create last-dump to aws');
            $last_dump = $local->get('last-dump.sql.gz');
            $wmdumps->put('maphub/'.$this->appName.'/last-dump.sql.gz', $last_dump);
            $this->log('db:upload_db_aws -> DONE create last-dump to aws');

            $this->log('db:upload_db_aws -> finished');

            return 0;
        } catch (CannotStartDump $e) {
            $this->log('db:upload_db_aws -> The dump process cannot be initialized: '.$e->getMessage(), 'error');
            $this->log('db:upload_db_aws -> Make sure to clear the config cache when changing the environment: `php artisan config:cache`', 'error');

            return 2;
        } catch (DumpFailed $e) {
            $this->log('db:upload_db_aws -> Error while creating the database dump: '.$e->getMessage(), 'error');

            return 1;
        }
    }

    protected function log($message, $type = 'info')
    {
        Log::$type($message);
        $this->$type($message);
    }
}
