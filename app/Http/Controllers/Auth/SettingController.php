<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Configuration;

class SettingController extends Controller
{
    public function payment_gateway()  {

        // $default_data = [
        //     'merchant_id' => 'SB_grtravel',
        //     'verify_key'  => '9c2def60ad030e526d0b8e48b900fbc2',
        //     'secret_key' => '7658ef8414c5f77825568b7ec0240866',
        //     'razer_url' => 'https://sandbox.merchant.razer.com/RMS/API/chkstat/returnipn.php?treq=0&sa=SB_grtravel',
        //     'email' => 'grtravel@domain.com',
        //     'password' => 'iJD21VCk',
        //     'submit_url' => 'https://sandbox.merchant.razer.com/RMS/pay/',
        //     'tax' => '2.6'
        // ];

        // dd($this->getRazerPay());

        return view('settings.payment_gateway', [
            'default_data' => $this->getRazerPay(),
        ]);
    }

    public function storePaymentGateway(Request $request){

        $validated = $request->validate([
            'merchant_id' => 'required',
            'verify_key' => 'required',
            'secret_key' => 'required',
            'razer_url' => 'required|url',
            'email' => 'required|email',
            'password' => 'required',
            'submit_url' => 'required|url',
            'tax' => 'nullable|numeric'
        ]);

        Configuration::updateGroup('razerpay', $validated);

        return response()->json(['message' => 'RazerPay configuration updated']);

    }

    public function getRazerPay()
    {
        return json_encode(Configuration::getGroup('razerpay'));
    }
}
