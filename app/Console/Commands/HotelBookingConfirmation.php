<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\HotelBooking;
use App\Services\RezliveHotelService;

class HotelBookingConfirmation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hotel-booking-confirmation';
    protected $RezliveHotelService;


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */

    public function __construct(RezliveHotelService $RezliveHotelService)
    {
        parent::__construct();
        $this->RezliveHotelService = $RezliveHotelService;
    }
    public function handle()
    {
        $this->RezliveHotelService->HotelBookingConfirmation();
    }
}
