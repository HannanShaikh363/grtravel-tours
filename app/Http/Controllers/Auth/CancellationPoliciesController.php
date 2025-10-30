<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CancellationPolicies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use ProtoneMedia\Splade\Facades\Toast;

class CancellationPoliciesController extends Controller
{

    public function store(Request $request)
    {

        $request->validate([
                'name' => ['required', 'string'], // Validate 'name' within the 'agent'
                'description' => ['required', 'string'],
                'type' => ['required', 'string', Rule::unique('cancellation_policies')->where(function ($query) use ($request) {
                    return $query->where('type', $request->type)->where('active',1);
                })]

            ]
        );

        $cancellationPolicies = new CancellationPolicies();
        $cancellationPolicies->user_id = Auth::user()->id;
        $cancellationPolicies->name = $request->name;
        $cancellationPolicies->description = $request->description;
        $cancellationPolicies->type = $request->type;
        $cancellationPolicies->cancellation_policies_meta = json_encode($request->policies);
        $cancellationPolicies->save();
        Toast::success('Cancellation Policies created successfully');
        return redirect()->back()->with('success', 'Cancellation Policies Limit created successfully');
    }


    public function destroy($id)
    {
        // Find the surcharge by ID
        $cancellationPolicies = CancellationPolicies::findOrFail($id);
        // Delete the surcharge
        $cancellationPolicies->active = 0;
        $cancellationPolicies->save();
        // Return a response or redirect
        Toast::title('Cancellation Policy deleted successfully')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
        // Redirect back with a success message
        return redirect()->route('agent.index')->with('status', 'cancellation-policies-deleted');

    }

}
