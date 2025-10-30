<?php

namespace App\Services;
use Illuminate\Support\Facades\Bus;
class ImportService
{

    public function getBatchProgress($batchId)
    {
        
        // $batchId = session('importGentingLastBatchID');

        if (!$batchId) {
            return response()->json(['error' => 'No batch found'], 404);
        }

        $batch = Bus::findBatch($batchId);
        if (!$batch) {
            return response()->json(['error' => 'Batch not found'], 404);
        }
        
        return response()->json([
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'failed_jobs' => $batch->failedJobs,
            'processed_jobs' => $batch->processedJobs(),
            'progress' => $batch->progress(),
            'finished' => $batch->finished(),
        ]);
    
    }
}
