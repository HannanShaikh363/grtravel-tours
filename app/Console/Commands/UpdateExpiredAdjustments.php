<?php

namespace App\Console\Commands;

use App\Models\AgentPricingAdjustment;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateExpiredAdjustments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'adjustments:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deactivate adjustments that have expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        // Update expired adjustments
        $affectedRows = AgentPricingAdjustment::where('active', 1)
            ->where('expiration_date', '<', $now)
            ->update(['active' => 0]);

        $this->info("Updated $affectedRows expired adjustments.");
    }
}
