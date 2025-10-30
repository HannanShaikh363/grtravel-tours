<?php

namespace App\Http\Controllers;

use App\Models\Surcharge;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\Facades\Toast;

class SurchargeController extends Controller
{

    public function show()
    {

        // Retrieve all surcharges
        $surcharges = Surcharge::with('country')->get(); // Assuming you have a relationship with a Country model

        // Pass surcharges to the view
        return view('rate.surcharge', compact('surcharges'));
    }

    public function store(Request $request)
    {
        // Validate the incoming request
        $request->validate([
            'surcharge_country' => 'required|exists:countries,id', // Assuming you have a countries table
            'surcharge' => 'required|numeric',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
        ]);

        // Check if a surcharge for this country already exists
        $surcharge = Surcharge::updateOrCreate(
            ['country_id' => $request->surcharge_country], // Find by country_id
            [
                'surcharge_percentage' => $request->surcharge,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
            ]
        );

        // Return a response or redirect
        Toast::title('Surcharge saved successfully')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return redirect()->route('rate.index')->with('status', 'surcharge-saved');
    }

    public function destroy($id)
    {
        // Find the surcharge by ID
        $surcharge = Surcharge::findOrFail($id);

        // Delete the surcharge
        $surcharge->delete();

        // Return a response or redirect
        Toast::title('Surcharge deleted successfully')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        // Redirect back with a success message
        return redirect()->route('rate.index')->with('status', 'surcharge-deleted');
    }
}
