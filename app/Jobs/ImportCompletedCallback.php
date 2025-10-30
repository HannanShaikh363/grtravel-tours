<?php
namespace App\Jobs;

use App\Models\User;
use App\Notifications\ImportCompleted;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportCompletedCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $filePath,
        protected int $userId
    ) {}

    /**
     * Handle the callback logic.
     * 
     * @return void
     */
    public function __invoke(Batch $batch)
    {
        // Cleanup file
        @unlink($this->filePath);
        
        // Notify the user with the batch information
        User::find($this->userId)->notify(
            new ImportCompleted($batch)
        );
    }

    /**
     * Handle the job's processing logic.
     * 
     * @return void
     */
    public function handle(Batch $batch)
    {
        // Optionally, you can call __invoke from handle if needed.
        $this->__invoke($batch);
    }
}
