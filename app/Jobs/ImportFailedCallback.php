<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ImportFailed;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportFailedCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $filePath,
        protected int $userId
    ) {}

    /**
     * Make this job callable.
     * 
     * @param Batch $batch
     * @param Throwable $e
     * @return void
     */
    public function __invoke(Batch $batch, Throwable $e)
    {
        // Cleanup file
        @unlink($this->filePath);
        
        // Notify the user with the error details
        User::find($this->userId)->notify(
            new ImportFailed($e)
        );
    }

    /**
     * Handle the job's processing logic.
     * 
     * @param Batch $batch
     * @param Throwable $e
     * @return void
     */
    public function handle(Batch $batch, Throwable $e)
    {
        // You can optionally call __invoke from here if needed
        $this->__invoke($batch, $e);
    }
}