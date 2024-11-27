<?php

namespace Wm\WmPackage\Jobs\Abstract;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

abstract class BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Get the Redis lock key for preventing job overlapping.
     * Must be implemented by child classes.
     */
    abstract protected function getRedisLockKey(): string;

    /**
     * Specify the job middleware.
     *
     * @return array
     */
    public function middleware()
    {
        $lockKey = $this->getLockKey();
        $this->logInfo('lockKey: '.$lockKey);

        return [(new WithoutOverlapping($lockKey, 60))->dontRelease()];
    }

    /**
     * Get the lock key that will be used to prevent overlapping jobs.
     *
     * @return string
     */
    protected function getLockKey()
    {
        $serializable = $this->getSerializableProperties();
        $lockKey = 'job_lock:'.static::class.':'.md5(serialize($serializable).':'.$this->getRedisLockKey());

        return $lockKey;
    }

    /**
     * Get the properties of the job that can be serialized.
     *
     * @return array
     */
    protected function getSerializableProperties()
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED);

        $serializable = [];
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($this);
            if (is_scalar($value) || is_array($value) || is_null($value)) {
                $serializable[$property->getName()] = $value;
            }
        }

        return $serializable;
    }

    /**
     * Get the job log file path.
     *
     * @return string
     */
    protected function getJobLogPath()
    {
        // Cerca prima nella configurazione dell'applicazione ospitante
        $hostLogPath = config('jobs.log_path');
        if ($hostLogPath) {
            return $hostLogPath;
        }

        // Altrimenti, usa un percorso predefinito nel package
        return storage_path('logs/jobs.log');
    }

    /**
     * Log an info message.
     *
     * @param  string  $message
     * @return void
     */
    protected function logInfo($message)
    {
        $jobName = class_basename($this);
        $formattedMessage = "[{$jobName}] {$message}";

        Log::channel($this->getLogChannel())->info($formattedMessage);
    }

    /**
     * Log an error message.
     *
     * @param  string  $message
     * @return void
     */
    protected function logError($message)
    {
        $jobName = class_basename($this);
        $formattedMessage = "[{$jobName}] {$message}";

        Log::channel($this->getLogChannel())->error($formattedMessage);
    }

    /**
     * Get the log channel for the job.
     * Must be implemented by child classes.
     */
    abstract protected function getLogChannel(): string;

    /**
     * Handle the job.
     *
     * @return void
     */
    abstract public function handle();

    /**
     * Handle a job failure.
     *
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        $this->logError('Job fallito: '.$exception->getMessage());
    }
}
