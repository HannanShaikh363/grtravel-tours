<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AgentPricingAdjustment;
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use ProtoneMedia\Splade\Facades\Toast;


class AgentPricingAdjustmentController extends Controller
{
    //


    public function index($type)
    {
        $agent_pricing_adjustments = AgentPricingAdjustment::all();

        return view('agent_pricing_adjustments.index', compact('agent_pricing_adjustments'));
    }


    public function store($id, Request $request)
    {
        
        $agentPricingAdjustment = AgentPricingAdjustment::findOrFail($id);
        // Only perform reactivation logic if this one is being activated
        if (!is_null($request->active)) {
            // Deactivate all other records with the same agent and transaction_type
            AgentPricingAdjustment::where('agent_id', $agentPricingAdjustment->agent_id)
                ->where('transaction_type', $agentPricingAdjustment->transaction_type)
                ->where('id', '!=', $agentPricingAdjustment->id)
                ->update(['active' => 0]);

            // Set this one as active
            $agentPricingAdjustment->active = 1;
        } else {
            // If unchecked, just set to inactive
            $agentPricingAdjustment->active = 0;
        }

        $agentPricingAdjustment->save();
        return redirect()->back()->with('success', 'Agent Pricing Adjustment created successfully');
    }


    public function create(Request $request)
    {
        $request->validate([
            'agent' => ['required', 'array'], // Ensure 'agent' is an array
            'agent.id' => ['required', 'integer'], // Validate 'id' within the 'agent'
            'agent.name' => ['required', 'string'], // Validate 'name' within the 'agent'
            'percentage' => ['required', 'integer'],
            'percentage_type' => ['required', 'string'],
            'transaction_type' => ['required','string'],
                // 'in:transfer,flight,tour,hotel,genting_hotel',
                // Rule::unique('agent_pricing_adjustments')->where(function ($query) use ($request) {
                //     if (isset($request->agent['id'])) {
                //         return $query->where('agent_id', $request->agent['id'])->where('active', 1);
                //     }
                // }),
            // 'effective_date' => ['required', 'date'],
            // 'expiration_date' => ['required', 'date'],
        ], [
            'agent.array' => 'The agent field must be an array.',
            'agent.required' => 'The agent name must be correct.',
            'agent.id.required' => 'The agent ID is required.',
            'agent.name.required' => 'The agent name is required.',
        ]);


        $agentId = $request->agent['id'];
        $transactionType = $request->transaction_type;

        $existing = AgentPricingAdjustment::where('agent_id', $agentId)
        ->where('transaction_type', $transactionType)
        ->where('active', 1)
        ->first();

        if ($existing) {
            // Update the existing record
            $existing->percentage = $request->percentage;
            $existing->percentage_type = $request->percentage_type;
            $existing->user_id = Auth::user()->id;
            $existing->save();
    
            Toast::success('Agent Pricing Adjustment updated successfully');
        }else {
            // Deactivate other records for same agent and transaction_type (if needed)
            AgentPricingAdjustment::where('agent_id', $agentId)
                ->where('transaction_type', $transactionType)
                ->update(['active' => 0]);

            $agent_pricing_adjustment = new AgentPricingAdjustment();
            $agent_pricing_adjustment->agent_id = $request->agent['id'];
            $agent_pricing_adjustment->percentage = $request->percentage;
            $agent_pricing_adjustment->percentage_type = $request->percentage_type;
            $agent_pricing_adjustment->transaction_type = $request->transaction_type;
            // $agent_pricing_adjustment->effective_date = $request->effective_date;
            // $agent_pricing_adjustment->expiration_date = $request->expiration_date;
            $agent_pricing_adjustment->user_id = Auth::user()->id;
            $agent_pricing_adjustment->active = 1;
            $agent_pricing_adjustment->save();
            Toast::success('Agent Pricing Adjustment created successfully');
        }

        return redirect()->back()->with('success', 'Agent Pricing Adjustment created successfully');
    }
    public function update($id){
        
    }


    public function destroy($id)
    {
        // Find the surcharge by ID
        $agentPricingAdjustment = AgentPricingAdjustment::findOrFail($id);

        // Delete the surcharge
        $agentPricingAdjustment->delete();

        // Return a response or redirect
        Toast::title('Adjustment deleted successfully')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        // Redirect back with a success message
        return redirect()->route('agent.index')->with('status', 'adjustment-deleted');
    }
}
