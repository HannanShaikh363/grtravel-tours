<?php

namespace App\Jobs;

use App\Jobs\ProcessHotelCsv;
use App\Jobs\ImportCompletedCallback;
use App\Jobs\ImportFailedCallback;
use App\Models\User;
use App\Notifications\ImportCompleted;
use App\Notifications\ImportFailed;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ProcessHotelImport implements ShouldQueue
{
   use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour
    public $tries = 1; // Let batch handle retries
    
    protected string $filePath;
    protected int $userId;

    public function __construct(string $filePath, int $userId)
    {
        $this->filePath = $filePath;
        $this->userId = $userId;
    }

    public function handle()
    {

        $jobs = $this->prepareJobs();

        if (empty($jobs)) {
            throw new \RuntimeException('No valid hotel data found in the file.');
        }
        $batch = Bus::batch($jobs)
        ->name('hotel_import_'.now()->format('Ymd_His'))
        ->then(new ImportCompletedCallback($this->filePath, $this->userId))
        ->catch(new ImportFailedCallback($this->filePath, $this->userId))
        ->dispatch();
            
        // $this->processFile($batch);
    }
    
    protected function  prepareJobs(): array
    {
        $config = [
            'chunk_size' => 2000, // Records per chunk
            'max_memory' => 50 * 1024 * 1024, // 50MB per chunk
            'skip_header' => true
        ];
        
        $file = new \SplFileObject($this->filePath);
        $file->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY);
        $file->setCsvControl('|');
        // $file->setCsvControl("\t");

        
        if ($config['skip_header']) {
            $file->seek(1); // Skip header row
        }
        
        $chunk = [];
        $chunkMemory = 0;
        $chunkNumber = 1;
        $jobs = [];
        
        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if (empty($row)) continue;
            
            $processedRow = array_map('trim', $row);

            // logger()->debug('CSV row', ['row' => count($processedRow)]);
            if (count($processedRow) < 4) continue;
            
            $rowMemory = strlen(serialize($processedRow));
            $chunk[] = $processedRow;
            $chunkMemory += $rowMemory;
            
            if (count($chunk) >= $config['chunk_size'] || 
                $chunkMemory >= $config['max_memory']) {
                
                // $batch->add(new ProcessHotelCsv(
                //     $chunk,
                //     $chunkNumber++
                // ));

                 $jobs[] = new ProcessHotelCsv($chunk, $chunkNumber++);
                
                $chunk = [];
                $chunkMemory = 0;
            }
        }
        logger()->info('Total jobs prepared', ['jobs' => count($jobs)]);

        // Add remaining records
        if (!empty($chunk)) {
            // $batch->add(new ProcessHotelCsv(
            //     $chunk,
            //     $chunkNumber
            // ));

             $jobs[] = new ProcessHotelCsv($chunk, $chunkNumber);
        }

        return $jobs;
    }
    
    public function failed(Throwable $exception)
    {
        // Cleanup file if job fails before creating batch
        @unlink($this->filePath);
        
        Notification::send(
            User::find($this->userId),
            new ImportFailed($exception)
        );
    }
}
