<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AgentFlightController extends Controller
{
    public function index(){
        return view("web.flight.dashboard");
    }
}
