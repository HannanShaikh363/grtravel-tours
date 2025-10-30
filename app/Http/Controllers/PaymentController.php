<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentController extends Controller
{
    // Display Payment Report
    public function index(Request $request)
    {
        $query = Payment::query();

        // Apply Filters
        if ($request->has('module')) {
            $query->where('module', $request->module);
        }
        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('transaction_date', [$request->date_from, $request->date_to]);
        }

        $payments = $query->latest()->paginate(10);

        return view('payments.index', compact('payments'));
    }

    // Store a new payment entry
    public function store(Request $request)
    {
        $request->validate([
            'reference_id' => 'required|string',
            'module' => 'required|string',
            'payment_method' => 'required|string',
            'amount' => 'required|numeric',
            'transaction_type' => 'required|in:Credit,Debit',
            'user_id' => 'nullable|exists:users,id',
            'transaction_date' => 'required|date',
        ]);

        Payment::create($request->all());

        return back()->with('success', 'Payment entry added successfully.');
    }
}
