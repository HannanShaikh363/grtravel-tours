<?php

namespace App\Services;
use App\Models\Payment;

class PaymentService
{
    public function createPayments($booking, $module, $payment_method, $amount, $transaction_type = 'Credit'){
        
        Payment::create([
            

            // action 1010101 cash 

            // v-no
            // v date
            // vtype
            // cheaque no
            // cheaque date
            // narration
            // total debit
            // total credit

            // detail

            // id
            // v-no
            // v-date
            // v-type

            // account code
            // narration
            // debit_pkr
            // credit_pkr
            // debit_fron
            // credit_forn
            // currency
            // exchange rate

            // d-c


            // SV-J Sales Voucher Journal 
            // GV-J General Voucher Journal
            // CV-R Cash Voucher Recipt

            
            'reference_id' => $booking->id,
            'module' => $module,
            'payment_method' => $payment_method,
            'amount' => $amount,
            'transaction_type' => $transaction_type,
            'user_id' => auth()->id(),
            'transaction_date' => now(),
        ]);

    }
    

}