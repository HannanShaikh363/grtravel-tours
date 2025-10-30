<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CancellationPolicies;
use App\Tables\AllBookingConfigurator;


class AllBookingController extends Controller
{
    //

    public function index()
    {

        return view('all_booking.index', ['all_booking' => new AllBookingConfigurator(), 'title' => 'Bookings Payments']);
    }

    public function store(){
        
    }
}
