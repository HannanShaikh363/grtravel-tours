<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Tables\FetchCurrencyRatesTableConfigurator;
use Illuminate\Http\Request;

class FetchCurrencyRatesController extends Controller
{
    public function index()
    {
        // $hotels = GentingHotel::get();
        return view('currencyRates.index', [
            'rate' => new FetchCurrencyRatesTableConfigurator(),
        ]);
    }
}
