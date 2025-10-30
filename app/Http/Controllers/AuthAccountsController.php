<?php

namespace App\Http\Controllers;

use App\Models\AgentAddCreditLimit;
use App\Services\AccountService;
use App\Tables\CreditLimitTableConfigurator;
use Illuminate\Http\Request;

class AuthAccountsController extends Controller
{
    protected $accountService;

    public function __construct(AccountService $accountService)
    {
        $this->accountService = $accountService;
    }
    
    public function index(Request $request){
        $staff = $this->accountService->getStaff($request->user());
        $totalBookings = $this->accountService->getTotalBookings($request->user());
        $totalCredit = $this->accountService->getTotalCredit($request->user());
        $allTimeAmount = $this->accountService->getTotalBookingAmount($request->user(), 'all');
        $monthlyAmount = $this->accountService->getTotalBookingAmount($request->user(), 'monthly');
        $yearlyAmount = $this->accountService->getTotalBookingAmount($request->user(), 'yearly');
        $totalBookingAmount =array_sum($allTimeAmount);
        $bookingType = $this->accountService->getBookingTypesCount($request->user());

        //per type
        $monthlyAmountType = $this->accountService->getTotalBookingAmountType($request->user(), 'monthly');
        $yearlyAmountType = $this->accountService->getTotalBookingAmountType($request->user(), 'yearly');
        return view('dashboard', compact('staff', 'totalBookings', 'totalBookingAmount', 'totalCredit', 'bookingType', 'monthlyAmount', 'yearlyAmount', 'monthlyAmountType', 'yearlyAmountType'));
    }

    public function creditLimitList(Request $request){

        return view('accounts.credit_limit_listing', [
            'credits' => new CreditLimitTableConfigurator(),

        ]);
    }

    public function creditLimitUsage(Request $request){

        return view('accounts.credit_limit_listing', [
            'credits' => new CreditLimitTableConfigurator(),

        ]);
    }
}
