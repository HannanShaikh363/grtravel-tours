<?php

namespace App\Console\Commands;


use App\Mail\TestMail;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class BookingEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:booking-email-confirmation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Booking Email Confirmation';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        Mail::to('d.raza@mmcgbl.com')->send(new TestMail());
    }
}
