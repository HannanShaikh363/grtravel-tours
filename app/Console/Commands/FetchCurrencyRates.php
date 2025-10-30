<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CurrencyService;
use App\Models\CurrencyRate;

class FetchCurrencyRates extends Command
{
    protected $signature = 'currency:fetch-rates';
    protected $description = 'Fetch and store daily currency rates';

    protected $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        parent::__construct();
        $this->currencyService = $currencyService;
    }

    public function handle()
    {
        $this->currencyService->fetchDailyRates();

    }
}
