<?php

namespace App\Http\Controllers;

use App\Models\GentingHotel;
use App\Models\GentingSurcharge;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\Facades\Toast;

class GentingSurchargeController extends Controller
{
    // public function index()
    // {
    //     $surcharges = GentingSurcharge::with('hotel')->get();
    //     return view('genting.index', compact('surcharges'));
    // }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'genting_hotel_id' => 'required|exists:genting_hotels,id',
            'surcharges' => 'required|array',
            'surcharges.*.surcharge_type' => 'required|in:fixed_date,weekend,date_range',
            'surcharges.*.surcharge_details' => 'required|array',
        ]);
        
        // Store everything in a single entry
        GentingSurcharge::create([
            'genting_hotel_id' => $validatedData['genting_hotel_id'],
            'surcharges' => json_encode($validatedData['surcharges']), // Store all surcharges as JSON
        ]);
        

        return redirect()->route('genting.index')->with('success', 'Surcharge added successfully.');
    }

    public function destroy($id)
    {
        // Find the surcharge by ID
        $surcharge = GentingSurcharge::findOrFail($id);

        // Delete the surcharge
        $surcharge->delete();

        // Return a response or redirect
        Toast::title('Surcharge deleted successfully')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        // Redirect back with a success message
        return redirect()->route('genting.index')->with('status', 'surcharge-deleted');
    }
}
