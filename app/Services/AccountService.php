<?php

namespace App\Services;

use App\Models\AgentAddCreditLimit;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class AccountService
{
    public function getTotalBookings($user)
    {
        // Check if the user is an admin
        if ($user->type === 'admin' || isStaffLinkedToAdmin($user)) {
            // Admin gets all bookings
            return [
                'total' => Booking::count(),
                'confirmed' => Booking::where('booking_status', 'confirmed')->count(),
                'vouchered' => Booking::where('booking_status', 'vouchered')->count(),
                'cancelled' => Booking::where('booking_status', 'cancelled')->count(),
                'pending_approval' => Booking::where('booking_status', 'pending_approval')->count(),
            ];
        } else {
            if ($user->type === 'agent') {
                // Agent: show only their own bookings
                return [
                    'total' => Booking::where('agent_id', $user->id)->count(),
                    'confirmed' => Booking::where('booking_status', 'confirmed')->where('agent_id', $user->id)->count(),
                    'vouchered' => Booking::where('booking_status', 'vouchered')->where('agent_id', $user->id)->count(),
                    'cancelled' => Booking::where('booking_status', 'cancelled')->where('agent_id', $user->id)->count(),
                    'pending_approval' => Booking::where('booking_status', 'pending_approval')->where('agent_id', $user->id)->count(),
                ];
            } elseif ($user->type === 'staff') {
                // Staff: show only bookings created by this specific staff user
                return [
                    'total' => Booking::where('agent_id', $user->id)->count(),
                    'confirmed' => Booking::where('booking_status', 'confirmed')->where('agent_id', $user->id)->count(),
                    'vouchered' => Booking::where('booking_status', 'vouchered')->where('agent_id', $user->id)->count(),
                    'cancelled' => Booking::where('booking_status', 'cancelled')->where('agent_id', $user->id)->count(),
                    'pending_approval' => Booking::where('booking_status', 'pending_approval')->where('agent_id', $user->id)->count(),
                ];
            }
        }
        
    }


    public function getStaff($user)
    {
        if ($user->type === 'admin' || isStaffLinkedToAdmin($user)) {
            $usersCount = User::selectRaw("
            type,
            COUNT(*) as count
        ")
        ->whereIn('type', ['agent', 'staff'])
        ->groupBy('type')
        ->get()
        ->mapWithKeys(function ($item) {
            // Ensure only staff have the `created_by_admin` condition
            if ($item->type === 'staff') {
                $count = User::where('type', 'staff')
                    ->where('created_by_admin', 1)
                    ->count();
            } else {
                $count = $item->count;
            }
            return [$item->type => $count];
        });
            
            return $usersCount;
        }

        return User::where('type', 'staff')
            ->where('agent_code', $user->agent_code)
            ->count();
    }

    public function getTotalCredit($user)
    {
        return User::whereIn('type', ['agent', 'staff'])
            ->sum('credit_limit');
    }

    public function getTotalBookingAmount($user, $filter = 'all')
    {
        $query = Booking::query();

        if ($user->type === 'agent') {
            $query->where('agent_id', $user->id);
        }

        // Initialize total amount variable
        $totalAmountMYR = 0;

        if ($filter === 'monthly') {
            $amounts = $query->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as period, amount, currency')->get();

        } elseif ($filter === 'yearly') {
            $amounts = $query->selectRaw('YEAR(created_at) as period, amount, currency')->get();

        } else {
            $amounts = $query->select('amount', 'currency')->get();
        }

        // Convert each amount to MYR
        foreach ($amounts as $booking) {
            $convertedAmount = CurrencyService::convertToMYR($booking->amount, $booking->currency);
            $totalAmountMYR += $convertedAmount;
        }

        // Group and sum amounts
        $groupedAmounts = $amounts->groupBy('period')->map(function ($group) {
            return $group->sum('amount');
        })->toArray();

        if ($filter === 'all') {
            $groupedAmounts = ['Total' => $totalAmountMYR];
        }

        // Apply currency conversion if needed
        $amountsInTargetCurrency = [];
        foreach ($groupedAmounts as $period => $amount) {
            $amountsInTargetCurrency[$period] = $this->applyCurrencyConversion($amount, 'MYR', $user->credit_limit_currency);
        }

        return $amountsInTargetCurrency;
    }



    public function applyCurrencyConversion($rate, $currentCurrency, $targetCurrency)
    {
        if ($targetCurrency) {
            $usdRate = CurrencyService::convertCurrencyToUsd($currentCurrency, $rate);
            return round(CurrencyService::convertCurrencyFromUsd($targetCurrency, $usdRate), 2);
        }
        return $rate;
    }

    public function getBookingTypesCount($user)
    {
        if ($user->type === 'admin' || isStaffLinkedToAdmin($user)) {
            return Booking::select('booking_type', DB::raw('COUNT(*) as total'))
                ->groupBy('booking_type')
                ->pluck('total', 'booking_type');
         }  elseif ($user->type === 'staff') {
                    // For staff, return their own bookings
                    return Booking::where('user_id', $user->id) // Assuming staff's ID is saved in user_id, adjust if needed
                        ->select('booking_type', DB::raw('COUNT(*) as total'))
                        ->groupBy('booking_type')
                        ->pluck('total', 'booking_type');
                } elseif ($user->type === 'agent') {
                    // For agents, return agent-created bookings
                    return Booking::where('agent_id', $user->id)
                        ->select('booking_type', DB::raw('COUNT(*) as total'))
                        ->groupBy('booking_type')
                        ->pluck('total', 'booking_type');
                }
    }

    public function getTotalBookingAmountType($user, $filter = 'all')
    {
        $query = Booking::query();

        // If the user is an agent, filter by their ID
        if ($user->type === 'agent') {
            $query->where('agent_id', $user->id);
        }

        // Apply filters based on the time period
        if ($filter === 'monthly') {
            $query->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as period, booking_type, currency, SUM(amount) as total')
                ->groupBy('period', 'booking_type', 'currency')
                ->orderBy('period', 'asc');
        } elseif ($filter === 'yearly') {
            $query->selectRaw('YEAR(created_at) as period, booking_type, currency, SUM(amount) as total')
                ->groupBy('period', 'booking_type', 'currency')
                ->orderBy('period', 'asc');
        } else {
            $query->selectRaw('"All Time" as period, booking_type, currency, SUM(amount) as total')
                ->groupBy('period', 'booking_type', 'currency');
        }

        $result = $query->get();

        // Convert each amount to MYR before final conversion
        $formattedData = [];
        foreach ($result as $row) {
            // Convert each booking amount to MYR
            $amountInMYR = CurrencyService::convertToMYR($row->total, $row->currency);

            // Apply the final conversion from MYR to the user's preferred currency
            $formattedData[$row->period][$row->booking_type] = $this->applyCurrencyConversion(
                $amountInMYR,
                'MYR',
                $user->credit_limit_currency
            );
        }

        return $formattedData;
    }

}