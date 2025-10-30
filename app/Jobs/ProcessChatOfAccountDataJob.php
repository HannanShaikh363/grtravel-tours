<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ChartOfAccount;
use ProtoneMedia\Splade\Facades\Toast;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessChatOfAccountDataJob implements ShouldQueue
{
    use Queueable, Batchable, SerializesModels;

    protected $chunkData;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(array $chunkData, $userId)
    {
        $this->chunkData = $chunkData;
        $this->userId = $userId;
    }


    /**
     * Execute the job.
     */
    public function handle(): bool
    {
        // ini_set('max_execution_time', '300');
        foreach ($this->chunkData as $column) {

            if (empty($column['account_code']) || empty($column['account_name'])) {
                Log::info('Missing required fields in the row.');
                return false;
            }

            try {

                $chartOfAccountData = [
                    'account_code' => $column['account_code'],
                    'account_name' => $column['account_name'] ?? '',
                    'parent_id' => $this->getParentId($column['parent_account_code']),
                    'nature' => $column['nature'] ?? '',
                    'level' => $column['level'] ?? '',
                    'type' => $column['type'] ?? '',
                    'currency' => $column['currency'] ?? 'MYR',
                    'status' => $column['status'] ?? 0,

                ];

                $chartOfAccount = ChartOfAccount::updateOrCreate(
                    [
                        'account_code' => $column['account_code'],
                    ],
                    $chartOfAccountData
                );

                Log::info("Processed record for Account: {$column['account_code']}, Account Name: {$column['account_name']}");
            } catch (\Exception $e) {

                Log::info('Error adding record: ' . $e);
                return false;
            }
        }

        return true; // Return true if all data is processed without critical errors
    }

    private function cleanText($text)
    {
        // Replace bullet points, tabs, and multiple dashes
        $text = str_replace(["•", "\t"], "-", $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, "- \t\n\r\0\x0B") ?: '-';
    }

    private function getParentId($parentAccountCode)
    {
        if (!$parentAccountCode) {
            return null; // No parent
        }

        $parent = ChartOfAccount::where('account_code', $parentAccountCode)->first();
        
        return $parent ? $parent->id : null; // Return Parent ID if found, otherwise null
    }
}
