<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\DiscountVoucher;
use App\Models\DiscountVoucherUser;
use App\Tables\DiscountVoucherTableConfigurator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ProtoneMedia\Splade\Facades\Toast;

class DiscountVoucherController extends Controller
{
    public function index()
    {
        return view('discountVoucher.index', [
            'voucher' => new DiscountVoucherTableConfigurator(),

        ]);
    }

    public function create()
    {
        return view('discountVoucher.create');
    }

    public function store(Request $request)
    {
        // Validate the request using your custom validation array
        $request->validate($this->voucherFormValidateArray());
        // Prepare the data using your helper method
        $data = $this->voucherData($request);

        // Store the voucher
        DiscountVoucher::create($data);

        Toast::title('Voucher Created')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
        // Redirect or respond
        return redirect()->route('discount_voucher.index')
            ->with('success', 'Discount voucher created successfully.');
    }

    public function edit($voucher_id)
    {
        $voucher = DiscountVoucher::where('id', $voucher_id)->first();
        return view('discountVoucher.edit', ['voucher' => $voucher]);
    }

    public function update(Request $request, DiscountVoucher $voucher)
    {
        // Validate the request using your custom validation rules
        $request->validate($this->voucherFormValidateArray());

        // Correct: Call update() on the instance, not the class
        $voucher->update([
            'currency' => $request->currency ?? "USD",
            'type' => $request->type,
            'value' => $request->value,
            'min_booking_amount' => $request->min_booking_amount,
            'max_discount_amount' => $request->max_discount_amount,
            'usage_limit' => $request->usage_limit,
            'per_user_limit' => $request->per_user_limit,
            'applicable_to' => $request->applicable_to,
            'valid_from' => $request->valid_from,
            'valid_until' => $request->valid_until,
            'status' => $request->status,
        ]);

        Toast::title('Voucher Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        return redirect()->route('discount_voucher.index')
            ->with('success', 'Discount voucher updated successfully.');
    }

    public function voucherFormValidateArray(): array
    {
        return [
            'type' => 'required|in:fixed,percentage',
            'value' => 'required|numeric|min:0.01',
            'min_booking_amount' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'valid_from' => 'required|date',
            'valid_until' => 'required|date|after_or_equal:valid_from',
            'applicable_to' => 'nullable|array',
            'applicable_to.*' => 'in:flights,genting_hotels,hotels,tours,transfers',
            'status' => 'required|in:active,inactive',
            'created_by' => 'nullable|exists:users,id',
        ];
    }

    public function voucherData(mixed $request)
    {
        return [
            'currency' => $request->currency ?? "USD",
            'code' => strtoupper(Str::random(6)),
            'type' => $request->type,
            'value' => $request->value,
            'min_booking_amount' => $request->min_booking_amount,
            'max_discount_amount' => $request->max_discount_amount,
            'usage_limit' => $request->usage_limit,
            'per_user_limit' => $request->per_user_limit,
            'applicable_to' => $request->applicable_to,
            'valid_from' => $request->valid_from,
            'valid_until' => $request->valid_until,
            'status' => $request->status,
        ];
    }

    public function validateCode(Request $request)
    {
        $code = $request->input('code');
        $bookingAmount = str_replace(',', '', $request->input('booking_amount')); // Make sure your JS sends this!
        $user = auth()->user()->getOwner();
        $now = now();

        $booking = Booking::where('id', $request->booking_id)->value('currency');

        $voucher = DiscountVoucher::where('code', $code)
            ->where('status', 'active')
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->first();

        if (!$voucher) {
            return response()->json(['valid' => false, 'message' => 'Invalid or expired voucher.']);
        }

        if (!is_null($voucher->currency) && !is_null($booking) && $voucher->currency != $booking && $voucher->type === 'fixed') {
            return response()->json(['valid' => false, 'message' => 'Voucher currency does not meet the currency of booking']);
        }

        if (!is_null($voucher->min_booking_amount) && $bookingAmount < $voucher->min_booking_amount) {
            return response()->json(['valid' => false, 'message' => 'Booking amount does not meet the minimum required for this voucher.']);
        }

        if (!is_null($voucher->usage_limit) && $voucher->used_count >= $voucher->usage_limit) {
            return response()->json(['valid' => false, 'message' => 'Voucher usage limit reached.']);
        }

        if (!is_null($voucher->per_user_limit)) {
            $userUsage = DiscountVoucherUser::where('user_id', $user->id)
                ->where('voucher_id', $voucher->id)
                ->value('usage_count');

            if (is_null($userUsage)) {
                return response()->json(['valid' => false, 'message' => "You're not allowed to use this voucher"]);
            }

            if ($userUsage >= $voucher->per_user_limit) {
                return response()->json(['valid' => false, 'message' => 'You have already used this voucher the maximum allowed times.']);
            }
        }

        return response()->json([
            'valid' => true,
            'type' => $voucher->type,
            'value' => $voucher->value,
            'min_booking_amount' => $voucher->min_booking_amount,
            'max_discount_amount' => $voucher->max_discount_amount,
            'currency' => $voucher->currency,
        ]);
    }


    public function getVouchers()
    {
        $now = now();

        $vouchers = DiscountVoucher::where('status', 'active')
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->select('id', 'code', 'type', 'value', 'applicable_to')
            ->get();

        $formatted = $vouchers->map(function ($voucher) {
            $value = $voucher->type === 'percentage'
                ? $voucher->value . '%'
                : '$' . number_format($voucher->value, 2);

            $applicableArray = is_array($voucher->applicable_to)
                ? $voucher->applicable_to
                : json_decode($voucher->applicable_to ?? '[]', true);

            $applicable = $applicableArray
                ? implode(', ', array_map('ucwords', $applicableArray))
                : 'All';

            return [
                'id' => $voucher->id,
                'label' => "{$voucher->code} - {$value} ({$applicable})",
            ];
        });

        return response()->json($formatted);
    }

    //Assign To
    public function create_assign_voucher($voucher_id)
    {
        $voucher = DiscountVoucher::findOrFail($voucher_id);

        $assignedUsers = DiscountVoucherUser::with('user.company')->where('voucher_id', $voucher->id)->get();
        $preselectedIds = $assignedUsers->pluck('user_id'); // [5, 10]
        $preselectedOptions = $assignedUsers->map(function ($item) {
            $company = $item->user->company->agent_name ?? '';
            return [
                'id' => $item->user->id,
                'username' => "{$item->user->username} - {$company}",
            ];
        });


        return view('discountVoucher.create_assign_voucher', [
            'voucher' => $voucher,
            'assignedUsers' => $assignedUsers,
            'preselectedIds' => $preselectedIds,
            'preselectedOptions' => $preselectedOptions,
        ]);
    }


    public function assign_voucher_store(Request $request)
    {
        $request->validate([
            'voucher_id' => 'required|exists:discount_vouchers,id',
            'user_id' => 'required|array',
            'user_id.*' => 'exists:users,id',
        ]);

        $voucherId = $request->voucher_id;
        $userIds = $request->user_id;

        DB::beginTransaction();

        try {
            foreach ($userIds as $userId) {
                // Prevent duplicate entries
                DiscountVoucherUser::updateOrCreate(
                    [
                        'voucher_id' => $voucherId,
                        'user_id' => $userId,
                    ],
                    [
                        'assigned_at' => now(),
                    ]
                );
            }

            DB::commit();

            return back()->with('success', 'Voucher assigned successfully to selected users.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to assign voucher. ' . $e->getMessage());
        }
    }

    public function destroyAssign($id)
    {
        DiscountVoucherUser::findOrFail($id)->delete();

        return back()->with('success', 'User removed from voucher successfully.');
    }


}
