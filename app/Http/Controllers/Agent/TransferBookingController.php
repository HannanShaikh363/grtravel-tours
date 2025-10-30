<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Mail\BookingApprovalPending;
use App\Mail\BookingApprovalPendingAdmin;
use App\Mail\BookingApproved;
use App\Mail\BookingCancel;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Models\CancellationPolicies;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\CurrencyRate;
use App\Models\DiscountVoucher;
use App\Models\DiscountVoucherUser;
use App\Models\FleetBooking;
use App\Models\TransferHotel;
use App\Models\Configuration;
use App\Models\User;
use App\Models\Location;
use App\Models\MeetingPoint;
use App\Models\Rate;
use App\Models\Surcharge;
use App\Models\VoucherRedemption;
use App\Rules\MaxSeatingCapacity;
use App\Services\BookingService;
use App\Services\CurrencyService;
use App\Tables\BookingTableConfigurator;
use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use App\Jobs\SendEmailJob;
use Illuminate\Support\Facades\Validator;

class TransferBookingController extends Controller
{
    protected $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }
    public function showBookings(Request $request)
    {

        $user = auth()->user();
        // Retrieve search inputs
        $search = $request->input('search'); // General search (if needed)
        $referenceNo = $request->input('user_id');
        $bookingId = $request->input('id');
        $reservationType = $request->input('booking_status');
        $transferName = $request->input('transfer_name');
        $pick_date = $request->input('pick_date');
        $pick_time = $request->input('pick_time');
        $fromLocation = $request->input('from_location');
        $toLocation = $request->input('to_location');
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $paylater = true;
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
            abort(403, 'This action is unauthorized.');
        }


        // Query the database with search and filters
        $bookings = FleetBooking::query()
            ->leftJoin('locations as from_location', 'fleet_bookings.from_location_id', '=', 'from_location.id')
            ->leftJoin('locations as to_location', 'fleet_bookings.to_location_id', '=', 'to_location.id')
            ->leftJoin('bookings', 'fleet_bookings.booking_id', '=', 'bookings.id') // Join with the bookings table
            ->select('fleet_bookings.*', 'from_location.name as from_location_name', 'to_location.name as to_location_name')
            // Filter based on user type
            ->when($user->type === 'agent', function ($query) use ($user) {
                // Include bookings for the agent and their staff
                $staffIds = User::where('type', 'staff')->where('agent_code', $user->agent_code)->pluck('id');
                return $query->where(function ($subQuery) use ($user, $staffIds) {
                    $subQuery->where('fleet_bookings.user_id', $user->id)
                        ->orWhereIn('fleet_bookings.user_id', $staffIds);
                });
            })
            ->when($user->type === 'staff' && !in_array($user->agent_code, $adminCodes), function ($query) use ($user) {
                return $query->where('fleet_bookings.user_id', $user->id);
            })
            ->when($referenceNo, function ($query, $referenceNo) {
                return $query->where('fleet_bookings.user_id', $referenceNo);
            })
            ->when($bookingId, function ($query, $bookingId) {
                return $query->where('bookings.booking_unique_id', 'like', '%' . $bookingId . '%');
            })
            ->when($reservationType, function ($query, $reservationType) {
                if ($reservationType !== '') {
                    return $query->where('bookings.booking_status', $reservationType); // '1' or '0'
                }
            })
            ->when($transferName, function ($query, $transferName) {
                return $query->where('transfer_name', 'like', "%{$transferName}%");
            })
            ->when($pick_date, function ($query, $pick_date) {
                // Ensure the input date is in 'Y-m-d' format for comparison
                return $query->whereDate('pick_date', '=', $pick_date);
            })
            ->when($pick_time, function ($query, $pick_time) {
                // Ensure time is in the correct format, and compare with `pick_time` field
                return $query->whereTime('pick_time', '=', $pick_time);
            })
            ->when($fromLocation, function ($query, $fromLocation) {
                return $query->where('from_location.name', 'like', "%{$fromLocation}%");
            })
            ->when($toLocation, function ($query, $toLocation) {
                return $query->where('to_location.name', 'like', "%{$toLocation}%");
            })
            ->with(['booking', 'booking.user'])
            ->orderBy('bookings.booking_date', 'desc')
            ->paginate(10)
            ->appends($request->all()); // Retain query inputs in pagination links

        // Total bookings count
        $totalBookings = FleetBooking::count();
        $offline_payment = route('offlineTransaction');
        $limit = auth()->user()->getEffectiveCreditLimit();

        return view('web.agent.bookingList', compact('bookings', 'totalBookings', 'offline_payment', 'paylater', 'limit'));
    }

    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create(Request $request)
    {
        // Validate the input fields
        $validated = $request->validate([
            'pickup_address' => 'nullable',
            'travel_location' => 'nullable',
            'dropoff_location' => 'nullable',
            'travel_date' => 'nullable|date',
            'travel_time' => 'nullable|date_format:H:i',
            'vehicle_seating_capacity' => 'nullable|integer|min:1',
            'vehicle_luggage_capacity' => 'nullable|integer|min:1',
            'currency' => 'nullable',
        ]);

        $showRates = collect();
        $toLocationIdListRate = collect();

        $dropoffAddress = $request->input('dropoff_address'); // Get the dropoff address


        $pick_date = $request->input('travel_date');
        $pick_time = $request->input('travel_time') ?? "00:00";
        $currency = $request->has('currency') ? $request->input('currency') : null;
        //From Travel location


        //Dropoff location
        $toLocationId = null;
        $fromLocationId = null;
        // $dropLocationName = $request->has('dropoff_location') && is_array($request->input('dropoff_location')) ? $request->input('dropoff_location')['name'] : null;


        // if (!is_null($dropLocationName)) {
        //     $toLocationId = Location::where('name', $dropLocationName)->value('id');
        // }

        if (is_array($request->input('travel_location'))) {


            $travelLocationName = $request->input('travel_location')['name'];
            $fromLocationId = Location::where('name', $travelLocationName)->first();
            if ($fromLocationId) {
                $countryId = $fromLocationId->country_id;
                $fromLocationId = $fromLocationId->id;
                if (!$request->input('advanceOpen')) {
                    if (!is_null($toLocationId))
                        $showRates = Rate::where('from_location_id', $fromLocationId)->where('to_location_id', $toLocationId);
                    else
                        $showRates = Rate::where('from_location_id', $fromLocationId);
                } else {


                    if ($request->has('dropoff_address') && in_array($request->input('dropoff_type'), ['airport', 'locality'])) {

                        $toLocationName = isset($request->input('dropoff_address')['name']) ? $request->input('dropoff_address')['name'] : null;
                        $toLocationId = Location::where('name', $toLocationName)->value('id');
                        $showRates = Rate::where('to_location_id', $toLocationId);
                        if ($request->input('pickup_type') == 'airport') {
                            $fromLocationName = isset($request->input('pickup_address')['name']) ? $request->input('pickup_address')['name'] : null;
                            $fromLocationId = Location::where('name', $fromLocationName)->value('id');
                            $showRates->where('from_location_id', $fromLocationId);
                        } else {

                            $showRates->where('from_location_id', $fromLocationId);
                        }
                    } else {

                        if (!is_null($toLocationId))
                            $showRates = Rate::where('to_location_id', $toLocationId)->where('from_location_id', $fromLocationId);
                        else
                            $showRates = Rate::where('from_location_id', $fromLocationId);
                    }


                    if ($request->has('vehicle_seating_capacity') && $request->input('vehicle_seating_capacity') > 0) {
                        $showRates->where('vehicle_seating_capacity', '>=', $request->input('vehicle_seating_capacity'));
                    }

                    if ($request->has('vehicle_luggage_capacity') && $request->input('vehicle_luggage_capacity') > 0) {
                        $showRates->where('vehicle_luggage_capacity', '>=', $request->input('vehicle_luggage_capacity'));
                    }
                }

                // Retrieve the rates
                if ($request->has('price') && $request->input('price') > 0) {
                    $showRates->where('rate', '>=', $request->input('price'))->orderBy('rate', 'ASC');
                }
                if ($request->has('destination') && !empty($request->input('destination'))) {
                    $showRates->where('to_location_id', $request->input('destination'));
                }
                if ($request->has('hour') && !empty($request->input('hour'))) {
                    $showRates->where('hours', $request->input('hour'))->orderBy('hours', 'asc');
                }
                if ($request->has('package') && !empty($request->input('package'))) {
                    $showRates->where('package', $request->input('package'));
                }
                $toLocationIdListRate = clone $showRates;
                $toLocationIdListRate = $toLocationIdListRate->select('to_location_id')->with('toLocation')->get();

                $showRates = $showRates->with('toLocation', 'fromLocation', 'transport')->paginate(20);
                $showRates->appends($request->query());
                $currentTime = Carbon::now()->format('Y-m-d H:i:s');
                $agentCode = auth()->user()->agent_code;

                $agentId = User::where('agent_code', $agentCode)
                    ->where('type', 'agent')
                    ->value('id');
                $adjustmentRate = AgentPricingAdjustment::where('agent_id', $agentId)->where('transaction_type', 'transfer')->where('active', 1)->where('effective_date', '<', $currentTime)->where('expiration_date', '>', $currentTime)
                    ->first();

                // Assuming travel_time is already in HH:MM format
                $travelTimeFormatted = $pick_time; // Keep it in HH:MM format

                // Convert the HH:MM formatted time to Carbon instances for comparison
                $travelTime = Carbon::createFromFormat('H:i', $travelTimeFormatted)->format('H:i:s');
                // Fetch surcharge based on country_id
                // $surcharge = Surcharge::where('country_id', $countryId)->whereRaw("'" . $travelTime . "'" . " BETWEEN start_time and end_time")->first();

                $surcharge = Surcharge::where('country_id', $countryId)
                    ->where(function ($query) use ($travelTime) {
                        $query->where(function ($subQuery) use ($travelTime) {
                            // Case 1: start_time <= end_time (same-day range)
                            $subQuery->whereRaw("'" . $travelTime . "' BETWEEN start_time AND end_time");
                        })
                            ->orWhere(function ($subQuery) use ($travelTime) {
                                // Case 2: start_time > end_time (cross-midnight range)
                                $subQuery->whereRaw("(start_time > end_time AND ('" . $travelTime . "' >= start_time OR '" . $travelTime . "' <= end_time))");
                            });
                    })->first();

                // Apply surcharges to rates
                foreach ($showRates as $rate) {
                    $countryId = $rate->fromLocation->country_id;
                    if (!is_null($currency)) {
                        $usd_rate = CurrencyService::convertCurrencyToUsd($rate->currency, $rate->rate);
                        $rate->rate = round(CurrencyService::convertCurrencyFromUsd($currency, $usd_rate), 2);
                        $rate->currency = $currency;
                    }

                    if ($surcharge) {
                        $rate->surcharge_percentage = $surcharge->surcharge_percentage;
                        $rate->rate = $rate->rate + ($rate->rate * ($surcharge->surcharge_percentage / 100));
                    } else {
                        $rate->surcharge_percentage = 0;
                    }
                    if (!is_null($adjustmentRate)) {
                        if ($adjustmentRate->percentage_type && $adjustmentRate->percentage_type === 'surcharge') {

                            $rate->rate = $rate->rate + ($rate->rate * ($adjustmentRate->percentage / 100));
                        } else {
                            $rate->rate = $rate->rate - ($rate->rate * ($adjustmentRate->percentage / 100));
                        }
                    }
                }
            }
        }
        // dd($showRates);
        if ($pick_time && $pick_date) {

            // Set the target date and time
            $targetDate = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $pick_date . ' ' . $pick_time . ':00'); // Replace with your target date and time
            // Get the current date and time
            $currentDate = Carbon::now();
            // Calculate the difference in days
            $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        }
        $remainingDays = $remainingDays ?? 0;

        return view('web.agent.partials.listItem', [
            'showRates' => $showRates,
            'dropoffAddress' => $dropoffAddress,
            'pick_date' => $pick_date,
            'pick_time' => $pick_time,
            'fromLocationId' => $fromLocationId,
            'remainingDays' => $remainingDays,
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            'toLocationId' => $toLocationId,
            'toLocationValues' => $toLocationIdListRate->pluck('toLocation')->unique(),
            'booking_date' => $pick_date, // Assuming the booking date is derived from $pick_date
        ]);
    }

    public function fetchlist(Request $request)
    {
        // Validate the input fields
        $validated = $request->validate([
            'pickup_address' => 'nullable',
            'travel_location' => 'nullable',
            'dropoff_location' => 'nullable',
            'travel_date' => 'nullable|date',
            'travel_time' => 'nullable|date_format:H:i',
            'vehicle_seating_capacity' => 'nullable|integer|min:1',
            'vehicle_luggage_capacity' => 'nullable|integer|min:1',
        ]);
        // $user = auth()->user();
        // $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        // if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
        //     abort(403, 'This action is unauthorized.');
        // }

        try {
            $parameters = $this->extractRequestParameters($request);
            $query = $this->buildQuery($parameters);
            $results = $this->applyFiltersAndPaginate($query, $parameters);
            $adjustedRates = $this->adjustRates($results, $parameters);
            return $this->prepareResponse($request, $adjustedRates, $parameters);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    private function extractRequestParameters(Request $request)
    {
        return [
            'dropoff_address' => $request->input('dropoff_address'),
            'pickup_address' => $request->input('pickup_address'),
            'travel_date' => $request->input('travel_date'),
            'travel_time' => $request->input('travel_time') ?? "00:00",
            'travel_location' => $request->input('travel_location'),
            'dropoff_location' => $request->input('dropoff_location'),
            'vehicle_seating_capacity' => $request->input('vehicle_seating_capacity'),
            'vehicle_luggage_capacity' => $request->input('vehicle_luggage_capacity'),
            'price' => $request->input('price'),
            'destination' => $request->input('destination'),
            'hour' => $request->input('hour'),
            'package' => $request->input('package'),
            'currency' => $request->input('currency'),
            'advanceOpen' => $request->input('advanceOpen'),
            'dropoff_type' => $request->input('dropoff_type'),
            'pickup_type' => $request->input('pickup_type'),
        ];
    }

    private function buildQuery(array $parameters)
    {
        $query = Rate::query();

        $user = auth()->user();
        $query->addSelect('rates.*');

        if ($user) {
            $query->selectRaw(
                "(SELECT COUNT(*) FROM wishlists WHERE wishlists.rate_id = rates.id AND wishlists.user_id = ?) > 0 AS wishlist_item",
                [$user->id]
            );
        } else {
            // If no user is logged in, return 0 (false) for the wishlist item attribute
            $query->selectRaw('0 AS wishlist_item');
        }

        if (!empty($parameters['travel_location'])) {
            $fromLocation = Location::where('name', $parameters['travel_location']['name'])->first();
            if ($fromLocation) {
                $query->where('from_location_id', $fromLocation->id);
            }
        }


        if (!empty($parameters['dropoff_address'])) {
            $toLocation = Location::where('name', $parameters['dropoff_address']['name'])->first();
            if ($toLocation) {
                $query->where('to_location_id', $toLocation->id);
            }
        }

        if (!empty($parameters['vehicle_seating_capacity'])) {
            $query->where('vehicle_seating_capacity', '>=', $parameters['vehicle_seating_capacity']);
        }

        if (!empty($parameters['vehicle_luggage_capacity'])) {
            $query->where('vehicle_luggage_capacity', '>=', $parameters['vehicle_luggage_capacity']);
        }

        return $query;
    }

    private function applyFiltersAndPaginate($query, array $parameters)
    {
        if (!empty($parameters['price'])) {
            $priceRange = $this->extractPriceRange($parameters['price']);
            $surcharge = $this->getSurcharge($parameters);

            // Apply price filtering with surcharge calculation
            if ($surcharge) {
                $query->whereRaw('(rate + (rate * ? / 100)) BETWEEN ? AND ?', [
                    $surcharge->surcharge_percentage,
                    $priceRange[0],
                    $priceRange[1]
                ]);
            } else {
                $query->whereBetween('rate', $priceRange);
            }
        }

        if (!empty($parameters['dropoff_location'])) {

            $toLocationId = Location::whereIn('name', explode(',', $parameters['dropoff_location']['name']))->value('id');
            if ($toLocationId) {
                $query->where('to_location_id', $toLocationId);
            }
        }

        if (!empty($parameters['hour'])) {
            $query->where('hours', $parameters['hour']);
        }

        if (!empty($parameters['package'])) {
            $query->whereIn('package', $parameters['package']);
        }

        // Travel Date filter (based on the Effective Date and Expiry Date)
        if (!empty($parameters['travel_date'])) {
            $travelDate = $parameters['travel_date']; // The user-provided travel date

            // Filter by Effective Date >= Travel Date and Expiry Date <= Travel Date
            $query->where(function ($query) use ($travelDate) {
                $query->whereDate('effective_date', '<=', $travelDate)
                    ->whereDate('expiry_date', '>=', $travelDate);
            });
        }

        $query->orderBy('rate', 'ASC');

        // dd($query->toSql(), $query->getBindings());


        $results = $query->with(['toLocation', 'fromLocation', 'transport'])->paginate(20);

        $results->appends($parameters);
        // dd($parameters , $results);
        return $results;
    }

    private function extractPriceRange($priceRanges)
    {
        $boundaries = [];
        foreach ($priceRanges as $range) {
            $parts = explode('_', $range);
            $boundaries[] = (int) $parts[0];
            if (isset($parts[1])) {
                $boundaries[] = (int) $parts[1];
            }
        }
        return [min($boundaries), max($boundaries)];
    }

    private function adjustRates($rates, array $parameters)
    {
        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        $surcharge = $this->getSurcharge($parameters);
        foreach ($rates as $rate) {
            // Apply currency conversion
            $rate->rate = $this->applyCurrencyConversion($rate->rate, $rate->currency, $parameters['currency']);
            // Apply surcharge
            $rate->rate = $this->applySurcharge($rate->rate, $surcharge);
            // Apply adjustments only if adjustments exist
            if ($adjustmentRates && $adjustmentRates->isNotEmpty()) {
                foreach ($adjustmentRates as $adjustmentRate) {
                    if ($adjustmentRate->transaction_type === 'transfer') {
                        // Pass the individual adjustment rate object
                        $rate->rate = $this->applyAdjustment($rate->rate, $adjustmentRate);
                    }
                }
            }
        }
        return $rates;
    }

    private function applyCurrencyConversion($rate, $currentCurrency, $targetCurrency)
    {
        if ($targetCurrency) {
            $usdRate = CurrencyService::convertCurrencyToUsd($currentCurrency, $rate);
            return round(CurrencyService::convertCurrencyFromUsd($targetCurrency, $usdRate), 2);
        }
        return $rate;
    }

    private function applySurcharge($rate, $surcharge)
    {
        return $surcharge ? $rate + ($rate * ($surcharge->surcharge_percentage / 100)) : $rate;
    }

    public function applyAdjustment($rate, $adjustmentRate)
    {
        // Check if $adjustmentRate is a valid object with the expected properties
        if ($adjustmentRate && isset($adjustmentRate->active, $adjustmentRate->percentage, $adjustmentRate->percentage_type)) {
            if ($adjustmentRate->active !== 0) {
                $percentage = $adjustmentRate->percentage;

                // Apply the adjustment based on percentage_type
                return $adjustmentRate->percentage_type === 'surcharge'
                    ? round($rate + ($rate * ($percentage / 100)), 2)
                    : round($rate - ($rate * ($percentage / 100)), 2);
            }
        }

        // If $adjustmentRate is invalid or inactive, return the original rate
        return $rate;
    }

    private function getSurcharge(array $parameters)
    {
        $currentTime = Carbon::createFromFormat('H:i', $parameters['travel_time'])->format('H:i:s');
        $country = $parameters['travel_location']['country'] ?? null;
        $countryId = Country::where('name', $country)->value('id');


        return $surcharge = Surcharge::where('country_id', $countryId)
            ->where(function ($query) use ($currentTime) {
                $query->where(function ($subQuery) use ($currentTime) {
                    // Case 1: start_time <= end_time (same-day range)
                    $subQuery->whereRaw("'" . $currentTime . "' BETWEEN start_time AND end_time");
                })
                    ->orWhere(function ($subQuery) use ($currentTime) {
                        // Case 2: start_time > end_time (cross-midnight range)
                        $subQuery->whereRaw("(start_time > end_time AND ('" . $currentTime . "' >= start_time OR '" . $currentTime . "' <= end_time))");
                    });
            })->first();
    }


    private function prepareResponse(Request $request, $adjustedRates, array $parameters)
    {
        $currency = $parameters['currency'];
        $surcharge = $this->getSurcharge($parameters);
        $surchargePercentage = $surcharge ? $surcharge->surcharge_percentage : 0;
        $nextPageUrl = $adjustedRates->nextPageUrl();
        // echo "<pre>";print_r($adjustedRates);die();
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_create = true;
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            $can_create = false;
        }
        // echo "<pre>";print_r($can_create);die();

        if ($request->ajax()) {
            return response()->json([
                'html' => view('web.agent.listItem', [
                    'showRates' => $adjustedRates,
                    'cancellationPolicy' => CancellationPolicies::getActivePolicyByType('transfer'),
                    'currency' => $currency,
                    'surcharge' => $surchargePercentage,
                    'can_create' => $can_create,
                ])->render(),
                'next_page' => $nextPageUrl,
            ]);
        }

        return view('web.agent.search', [
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            'showRates' => $adjustedRates,
            'booking_date' => $parameters['travel_date'],
            'filters' => $this->getFilters($parameters),
            'currency' => $currency,
            'surcharge' => $surchargePercentage,
            'next_page' => $nextPageUrl,
            'can_create' => $can_create,

        ]);
    }


    private function getFilters(array $parameters)
    {
        // Retrieve the from_location_id based on the travel_location name
        $fromLocationId = Location::where('name', $parameters['travel_location']['name'])->value('id');

        // Get distinct to_location_id values where from_location_id matches
        $locationIds = Rate::where('from_location_id', $fromLocationId)->distinct()->pluck('to_location_id');

        return [
            'destinations' => Location::whereIn('id', $locationIds)->pluck('name'),
            'vehicleTypes' => Rate::whereIn('to_location_id', $locationIds)->distinct()->pluck('package'),
            'prices' => Rate::whereIn('to_location_id', $locationIds)->distinct()->pluck('rate')->map(fn($rate) => (int) $rate),
        ];
    }


    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $packages = Rate::select('package')
            ->where('currency', 'MYR')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();


        $rates = collect();


        foreach ($packages as $package) {
            $rate = Rate::where('package', $package->package)
                ->latest()
                ->first();

            $rates->push($rate);
        }

        return view('web.agent.dashboard', ['rates' => $rates]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->fleetBookingFormValidation($request));

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }
        $data = $request->all();
        $request = new Request($data);
        $subtotal = 0;

        $rateType = Rate::where('id', $request->input('rate_id'))->first();

        $pickup_time = null;
        if (stripos($rateType->fromLocation->name, 'airport') !== false) {
            $pickup_time = $request->input('flight_arrival_time');
        }

        $fleetBookingData = $this->fleetData($request, $pickup_time);

        $booking_status = 'confirmed';
        $payment_type = 'pay_later';
        // $rate = Rate::where('id', $request->input('rate_id'))->firstOrFail();
        $rate = Rate::with('fromLocation')->where('id', $request->input('rate_id'))->first();
        $currentTime = Carbon::createFromFormat('H:i', $request->pick_time)->format('H:i:s');

        $surcharge = Surcharge::where('country_id', $rate->fromLocation->country_id)
            ->where(function ($query) use ($currentTime) {
                $query->where(function ($subQuery) use ($currentTime) {
                    // Case 1: start_time <= end_time (same-day range)
                    $subQuery->whereRaw("'" . $currentTime . "' BETWEEN start_time AND end_time");
                })
                    ->orWhere(function ($subQuery) use ($currentTime) {
                        // Case 2: start_time > end_time (cross-midnight range)
                        $subQuery->whereRaw("(start_time > end_time AND ('" . $currentTime . "' >= start_time OR '" . $currentTime . "' <= end_time))");
                    });
            })->first();

        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);

        // Step 1: Start with base rate
        $originalRate = $rate->rate;

        // Step 2: Apply surcharge
        $originalRate = $this->applySurcharge($originalRate, $surcharge);

        // Step 3: Apply adjustments if any
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            // Filter adjustment rates for transaction_type === 'transfer'
            $transferRates = $adjustmentRate->filter(function ($rate) {
                return $rate->transaction_type === 'transfer';
            });

            foreach ($transferRates as $transferRate) {
                $originalRate = $this->applyAdjustment($originalRate, $transferRate);
            }
        }
        // Step 4: Apply currency conversion at the end
        $booking_rate = $this->applyCurrencyConversion($originalRate, $rate->currency, $request->currency);


        $rate_price = $rate->rate;

        $journeyType = $request->input('journey_type');
        $booking_currency = $request->input('currency') ?? $rate->currency;
        $rateCurrency = $rate->currency ?? $request->input('currency');

        if ($rate->journey_type === 'two_way' || $request->input('journey_type') === 'two_way') {
            $ratePrice = round($booking_rate * 2, 2);
            $originalRate = round($originalRate * 2, 2);
        } else {
            $ratePrice = round($booking_rate, 2) ?? 0;
            $originalRate = round($originalRate, 2) ?? 0;
        }

        $subtotal = $ratePrice;
        $fleetBookingData['booking_cost'] = $subtotal;
        $currencyRate = CurrencyRate::where('target_currency', $request->currency)->first();
        $netCurrencyRate = CurrencyRate::where('target_currency', $rate->currency)->first();
        $user = auth()->user()->getOwner();

        if ($request->submitButton == "pay_offline" || $request->submitButton == "request_booking") {
            //Voucher Discount
            $discountPrice = 0;
            $discountedTourPrice = 0;
            if ($request->get('voucher_code') != null) {
                $max_booking_cap = 0;
                $voucher = DiscountVoucher::where('code', $request->voucher_code)
                    ->where(function ($query) {
                        $query->whereNull('usage_limit') // unlimited
                            ->orWhereColumn('used_count', '<', 'usage_limit');
                    })
                    ->where('status', 'active') // optional: ensure it's not disabled
                    ->first();

                if (!$voucher) {
                    return back()->with('error', 'Invalid Voucher Code');
                }

                if (!is_null($voucher->min_booking_amount) && $ratePrice <= $voucher->min_booking_amount) {
                    return back()->with('error', 'Booking amount does not meet the minimum required for this voucher.');
                }

                // Get user's usage from pivot table
                $userUsage = DiscountVoucherUser::where('user_id', $user->id)
                    ->where('voucher_id', $voucher->id)
                    ->first();

                if ($voucher->per_user_limit !== null) {
                    $usageCount = $userUsage?->usage_count ?? 0;

                    if ($usageCount >= $voucher->per_user_limit) {
                        return back()->with('error', 'Voucher usage limit reached for user.');
                    }
                }

                $now = now();

                if (
                    ($voucher->valid_from && $voucher->valid_from > $now) ||
                    ($voucher->valid_until && $voucher->valid_until < $now)
                ) {
                    return back()->with('error', 'Invalid or expired voucher code.');
                }

                if ($voucher) {

                    if ($voucher->type === 'fixed') {
                        $discountPrice = $voucher->value;
                        if ($voucher->currency != $booking_currency) {
                            $discountPrice = CurrencyService::convertCurrencyTOUsd($voucher->currency, $discountPrice);
                            $discountPrice = CurrencyService::convertCurrencyFromUsd($booking_currency, $discountPrice);
                        }
                    } elseif ($voucher->type === 'percentage') {
                        $discountPrice = ($voucher->value / 100) * $ratePrice; // Convert booking amount to USD
                        if (!is_null($voucher->max_discount_amount) && $discountPrice > $voucher->max_discount_amount) {
                            $discountPrice = $voucher->max_discount_amount;
                        }
                    }
                    $discountedTourPrice = max(0, $ratePrice - $discountPrice);
                }
            }
            $deductionResult = $this->bookingService->deductCredit($user, $discountedTourPrice ? $discountedTourPrice : $ratePrice, $booking_currency);
            if ($deductionResult !== true) {
                return response()->json([
                    'success' => false,
                    'error' => 'Insufficient credit limit to create booking.',
                ], 400);
            }
        }

        if ($request->submitButton == "pay_online") {

            $payment_type = 'card';
            $booking_status = 'confirmed';

        }

        $flightDepartureTime = $flightDepartureTime ?? null;
        $returnFlightDepartureTime = $returnFlightDepartureTime ?? null;
        $flightArrivalTime = $flightArrivalTime ?? null;
        $returnArrivalDepartureTime = $returnArrivalDepartureTime ?? null;

        $dropOffName = null;
        $pickUpName = null;
        $cancellation = CancellationPolicies::where('active', 1)->where('type', 'transfer')->first();
        $maxDeadlineDate = null;

        if ($cancellation) {
            $cancellationPolicyData = json_decode($cancellation->cancellation_policies_meta, true);

            $cancellationPolicyCollection = collect($cancellationPolicyData);

            // Sort by 'days_before' in ascending order
            $sortedCancellationPolicy = $cancellationPolicyCollection->sortBy('days_before')->values()->toArray();
            $targetDate = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $request->input('pick_date') . ' ' . $request->input('pick_time') . ':00'); // Replace with your target date and time
            // Get the current date and time
            $currentDate = Carbon::now();
            // Calculate the difference in days
            $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past

            $remainingDays = $remainingDays ?? 0;
            $maxDeadlineDay = 0;
            foreach ($sortedCancellationPolicy as $key => $value) {
                if ($remainingDays >= $sortedCancellationPolicy[$key]['days_before']) {
                    $maxDeadlineDay = $sortedCancellationPolicy[$key]['days_before'];
                    break;
                }
            }
            // Calculate the max deadline date based on the remaining days
            if ($maxDeadlineDay > 0)
                $maxDeadlineDate = $targetDate->subDays($maxDeadlineDay)->format('Y-m-d H:i:s');
            else
                $maxDeadlineDate = $targetDate->format('Y-m-d H:i:s');
        }
        $action = $request->input('submitButton');
        if ($action === 'request_booking') {
            $booking_status = 'pending_approval';
        }elseif ($action === 'pay_offline') {
             $booking_status = 'vouchered';
            $payment_type = 'wallet';
        }
        $netCurrency = $rate->currency ?? '';
        $netRate = 0;
        $baseRate = $rate->rate ?? 0;
        // Apply surcharge if within time window
        if ($surcharge && $surcharge->surcharge_percentage) {
            $percentage = $surcharge->surcharge_percentage;
            $netRate = $baseRate + ($baseRate * ($percentage / 100));
        } else {
            $netRate = $baseRate;
        }
        if ($request->input('journey_type') === 'two_way') {
            $netRate = $netRate * 2;
        }

        $bookingData = [
            'agent_id' => auth()->id(),
            'user_id' => auth()->id(),
            'booking_date' => now()->format('Y-m-d H:i:s'),
            'amount' => $ratePrice,
            'currency' => $booking_currency,
            'service_date' => $request->input('pick_date') . ' ' . ($pickup_time ? $pickup_time : $request->input('pick_time')),
            // 'deadline_date' => $booking_status === 'vouchered' ? now()->format('Y-m-d H:i:s') : $maxDeadlineDate,
            'deadline_date' => $maxDeadlineDate,
            'booking_type' => 'transfer',
            'booking_status' => $booking_status,
            'payment_type' => $payment_type,
            'subtotal' => $subtotal,
            'conversion_rate' => $currencyRate->rate,
            'original_rate' => $originalRate,
            'original_rate_conversion' => $netCurrencyRate->rate,
            'original_rate_currency' => $rate->currency,
            'net_rate' => $netRate,
            'net_rate_currency' => $netCurrency,
        ];


        // Create the booking and fleet booking
        try {
            DB::beginTransaction();
            $bookingSaveData = Booking::create($bookingData);
            $fleetBooking = FleetBooking::create($fleetBookingData);
            if ($request->submitButton == "pay_offline" && $request->get('voucher_code') != null && $voucher) {
                $this->storeVoucherRedemptions($voucher, $user, $bookingSaveData->id, $discountPrice);
            }
            $fleetBooking->load('driver');

            // Approve the booking
            $fleetBooking->update(['approved' => true]);
            $fleetBooking->update(['booking_cost' => $ratePrice]);
            // Check if the authenticated user is an admin
            $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system
            // If created by admin, mark as approved
            if ($isCreatedByAdmin) {
                $bookingSaveData->update(['created_by_admin' => true, 'booking_type_id' => $fleetBooking->id]);
                $fleetBooking->update(['created_by_admin' => true, 'approved' => true, 'booking_id' => $bookingSaveData->id]);
            } else {
                $fleetBooking->update(['booking_id' => $bookingSaveData->id]);
                $bookingSaveData->update(['booking_type_id' => $fleetBooking->id]);
            }

            // Now, store hotel locations in the pivot table for pickup
            if ($request->has('pickup_address')) {
                // Get the pickup address details
                $pickupLocation = $request->input('pickup_address');
                // $cleanInputpickup = $pickupLocation['name'] ? preg_replace('/[^A-Za-z0-9 ]/', '', $pickupLocation['name']) : null;

                // Check if return_pickup_address is also present and get its clean name
                $returnPickupLocation = $request->input('pickup_address');
                $cleanInputReturnPickup = $returnPickupLocation
                    ? $returnPickupLocation
                    : null;
                // Check if the booking is 'two_way'
                $isTwoWay = $fleetBooking->journey_type === 'two_way'; // Adjust this condition based on your actual field name and value

                // Prepare the data to attach
                $attachData = [
                    'booking_id' => $fleetBooking->id,
                    'pickup_hotel_name' => $cleanInputReturnPickup,
                    'hotel_location_id' => $pickupLocation['id'] ?? null,
                    'latitude' => $pickupLocation['latitude'] ?? null,
                    'longitude' => $pickupLocation['longitude'] ?? null,
                ];

                // Only add 'return_pickup_hotel_name' if booking is 'two_way'
                if ($isTwoWay && $cleanInputReturnPickup) {
                    $attachData['return_dropoff_hotel_name'] = $cleanInputReturnPickup;
                }

                // Use updateOrCreate to handle both pickup and return hotel names in a single record
                TransferHotel::updateOrCreate(
                    $attachData,
                    [] // No additional fields to update
                );
            }

            // Now, store hotel locations in the pivot table for drop-off
            if ($request->has('dropoff_address')) {
                // Get the dropoff address details
                $dropOffLocation = $request->input('dropoff_address');
                // $cleanInputDropOff = $dropOffLocation['name'] ? preg_replace('/[^A-Za-z0-9 ]/', '', $dropOffLocation['name']) : null;

                // Check if return_pickup_address is also present and get its clean name
                $returnPickupLocation = $request->input('dropoff_address');
                $cleanInputReturnPickup = $dropOffLocation
                    ? $dropOffLocation
                    : null;

                // Check if the booking is 'two_way'
                $isTwoWay = $fleetBooking->journey_type === 'two_way'; // Adjust this condition based on your actual field name and value

                // Prepare the data to attach
                $attachData = [
                    'booking_id' => $fleetBooking->id,
                    'dropoff_hotel_name' => $cleanInputReturnPickup,
                    'hotel_location_id' => $dropOffLocation['id'] ?? null,
                    'latitude' => $dropOffLocation['latitude'] ?? null,
                    'longitude' => $dropOffLocation['longitude'] ?? null,
                ];

                // Only add 'return_dropoff_hotel_name' if booking is 'two_way'
                if ($isTwoWay && $cleanInputReturnPickup) {
                    $attachData['return_pickup_hotel_name'] = $cleanInputReturnPickup;
                }

                // Use updateOrCreate to handle both dropoff and return hotel names in a single record
                TransferHotel::updateOrCreate(
                    $attachData,
                    [] // No additional fields to update
                );
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback(); // Roll back if there's an error.

            Toast::title($e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);

            // if ($request->ajax()) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            // }

            // return Redirect::back()->withErrors(['error' => $e->getMessage()]);
        }

        //only use when difference is 12 hours
        if ($action === 'request_booking') {
            // Update booking approval status to pending
            $fleetBooking->approved = 0;
            $fleetBooking->sent_approval = 1;
            $admin = User::where('type', 'admin')->first();
            // Save the changes
            $fleetBooking->save();
            // Send approval pending email to the agent
            $agentEmail = auth()->user()->email;
            $agentName = auth()->user()->first_name;
            $bookingType = $bookingSaveData->booking_type;

            // Mail::to($agentEmail)->send(new BookingApprovalPending($fleetBooking, $agentName));
            $mailInstance = new BookingApprovalPending($fleetBooking, $agentName, $bookingSaveData);
            SendEmailJob::dispatch($agentEmail, $mailInstance);
            $mailInstance = new BookingApprovalPendingAdmin($fleetBooking, $bookingSaveData, $admin->first_name, $bookingType);
            SendEmailJob::dispatch($admin->email, $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_tour'), $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_info'), $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_account'), $mailInstance);
            // Mail::to($admin->email)->send(new BookingApprovalPendingAdmin($fleetBooking, $bookingData, $admin->first_name));
        } else {
            // Prepare data for PDF
            $bookingData = $this->bookingService->prepareBookingData($request, $fleetBooking, $dropOffName, $pickUpName, $is_updated = null);

            $passenger_email = $request->input('passenger_email_address');
            $hirerEmail = $user->email;

            // Create and send PDF
            // dd('Test 4',$bookingData, $fleetBooking);
            $this->bookingService->createBookingPDF($bookingData, $hirerEmail, $request, $fleetBooking);

            if ($request->submitButton == "pay_online") {
                return response()->json([
                    'success' => true,
                    'message' => 'pay_online',
                    'redirect_url' => $this->processPayment($bookingSaveData),
                ]);
                // return redirect($this->processPayment($bookingSaveData));
            }

            if ($request->submitButton == "pay_offline") {

                Toast::title('Payment Done.')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
            }
        }
        session()->flash('success', 'Booking successfully created!');
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
            return response()->json([
                'success' => true,
                'message' => 'Booking successfully created!',
                'redirect_url' => route('web.dashboard'),
            ]);
        }
        return response()->json([
            'success' => true,
            'message' => 'Booking successfully created!',
            'redirect_url' => route('mybookings.index'),
        ]);
    }


    public function processPayment($bookingSaveData)
    {

        $merchantID = env('RAZER_MERCHANT_ID');
        $verifyKey = env('RAZER_VERIFY_KEY');
        $tax_percent = Configuration::getValue('razerpay', 'tax', 0);
        $subtotal = $bookingSaveData->subtotal;
        $tax_amount = $subtotal * ($tax_percent / 100);
        $priceAfterTax = round(($subtotal + $tax_amount), 2);
        $vcode = md5($priceAfterTax . $merchantID . $bookingSaveData->id . $verifyKey);

        $company = auth()->user()->company()->with('country')->first();
        $country = $company->country->iso2 ?? 'MY';

        $payload = [
            'amount' => $priceAfterTax,
            'orderid' => $bookingSaveData->id,
            'currency' => $bookingSaveData->currency,
            'bill_name' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
            'bill_email' => auth()->user()->email,
            'bill_mobile' => auth()->user()->mobile,
            'bill_desc' => 'GR TOUR & TRAVEL - Transfer Booking: ' . $bookingSaveData->id,
            'country' => $country,
            'vcode' => $vcode,
        ];

        return env('RAZER_SUBMIT_URL') . $merchantID . '/?' . http_build_query($payload);
    }

    public function show(User $user)
    {
        $user->toArray();
        exit;
        //return view('job.job', ['job' => $job]);
    }

    public function edit($id)
    {
        $booking = FleetBooking::where('id', $id)->first();
        // $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        return view('booking.edit', ['booking' => $booking]);
    }

    public function update($booking)
    {
        $request = request();
        $request->validate($this->fleetBookingFormValidation($request));
        $booking->update($this->fleetData($request));
        Toast::title('Passenger Information Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
        session()->forget('editForm');
        return redirect()->back()->with('success', 'Passenger information updated successfully.');
    }

    public function destroy(User $user)
    {
        $user->toArray();
        exit();
        //return view('job.job', ['job' => $job]);
    }

    /**
     * @return array
     */
    public function fleetBookingFormValidation(Request $request): array
    {
        return [
            "transport_id" => ['nullable',],
            "pick_time" => ['required',],
            "passenger_title" => ['required',],
            "passenger_full_name" => ['required',],
            "passenger_contact_number" => ['required',],
            "phone_code" => ['required',],
            "passenger_email_address" => ['nullable',],
            "agent_id" => ['nullable',],
            "booking_date" => ['nullable',],
            "pick_date" => ['required',],
            "dropoff_date" => ['nullable',],
            "rate_id" => ['nullable',],
            // "booking_cost" => ['required',],
            "nationality_id" => ['nullable',],
            "return_pickup_date" => ['nullable', 'date', 'after_or_equal:pick_date'],
            "return_pickup_address" => ['nullable'],
            'arrival_flight_date' => ['nullable', 'date', 'after_or_equal:pick_date'],
            'depart_flight_date' => ['nullable', 'date', 'after_or_equal:pick_date'],
            "vehicle_seating_capacity" => [
                'nullable',
                'integer',
                'min:1',
                new MaxSeatingCapacity($request->rate_id), // Apply the custom rule
            ],
        ];
    }

    /**
     * @param mixed $request
     * @return array
     */
    public function fleetData(mixed $request, $pickup_time): array
    {
        // $bookingCost = number_format($request->booking_cost, 2);
        return [
            'transport_id' => $request->transport_id,
            'from_location_id' => $request->from_location_id,
            'to_location_id' => $request->to_location_id,
            'nationality_id' => $request->nationality_id,
            'vehicle_seating_capacity' => $request->vehicle_seating_capacity,
            'vehicle_luggage_capacity' => $request->vehicle_luggage_capacity,
            'passenger_title' => $request->passenger_title,
            'passenger_full_name' => $request->passenger_full_name,
            'passenger_contact_number' => $request->passenger_contact_number,
            'phone_code' => $request->phone_code,
            'passenger_email_address' => $request->passenger_email_address,
            'pick_time' => $pickup_time ?? $request->pick_time,
            "pick_date" => $request->pick_date,
            "dropoff_date" => $request->dropoff_date,
            "rate_id" => $request->rate_id,
            // "booking_cost" => str_replace(',', '', $bookingCost),
            "total_cost" => $request->total_cost,
            "depart_airline_code" => $request->depart_airline_code,
            "arrival_airline_code" => $request->arrival_airline_code,
            "arrival_flight_number" => $request->arrival_flight_number,
            "depart_flight_number" => $request->depart_flight_number,
            "depart_flight_date" => $request->depart_flight_date,
            "flight_departure_time" => $request->flight_departure_time,
            "arrival_flight_date" => $request->arrival_flight_date,
            "flight_arrival_time" => $request->flight_arrival_time,
            "return_arrival_flight_date" => $request->return_arrival_flight_date,
            "return_arrival_flight_number" => $request->return_arrival_flight_number,
            "return_depart_flight_date" => $request->return_depart_flight_date,
            "return_depart_flight_number" => $request->return_depart_flight_number,
            "return_flight_departure_time" => $request->return_flight_departure_time,
            "return_flight_arrival_time" => $request->return_flight_arrival_time,
            "hours" => $request->hours,
            "transfer_name" => $request->transfer_name,
            "journey_type" => $request->journey_type,
            "vehicle_model" => $request->vehicle_model,
            "vehicle_make" => $request->vehicle_make,
            "meeting_point" => $request->meeting_point,
            "airport_type" => $request->airport_type,
            "return_pickup_date" => $request->return_pickup_date,
            "return_pickup_time" => $request->return_pickup_time,
            "currency" => $request->currency,
            "user_id" => auth()->user()->id,
            "arrival_terminal" => $request->arrival_terminal,
            "return_arrival_terminal" => $request->return_arrival_terminal,
            'package' => $request->package,
        ];
    }

    public function searchByCapacity(Request $request): JsonResponse
    {
        // Validate the input to ensure seating and luggage capacity are numeric
        $request->validate([
            'vehicle_seating_capacity' => ['nullable', 'integer'],
            'vehicle_luggage_capacity' => ['nullable', 'integer'],
        ]);

        // Retrieve the input values
        $seatingCapacity = $request->input('vehicle_seating_capacity');
        $luggageCapacity = $request->input('vehicle_luggage_capacity');

        // Query to match both seating and luggage capacity
        $rates = Rate::select('id', 'vehicle_seating_capacity', 'vehicle_luggage_capacity')
            ->when($seatingCapacity, function ($query, $seatingCapacity) {
                return $query->where('vehicle_seating_capacity', $seatingCapacity);
            })
            ->when($luggageCapacity, function ($query, $luggageCapacity) {
                return $query->where('vehicle_luggage_capacity', $luggageCapacity);
            })
            ->get();

        return response()->json($rates);
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'status' => 'required|in:confirmed,pending',
        ]);

        // Update the booking status
        $booking = FleetBooking::find();
        $booking->status = 'confirmed'; // Or set it based on your logic
        $booking->save();

        return redirect()->route('booking.index')->with('status', 'Status updated successfully!');
    }

    public function approve($id)
    {
        // Fetch the booking or fail if not found
        $fleetBooking = FleetBooking::findOrFail($id);

        // Determine if the currently authenticated user is an admin
        $isCreatedByAdmin = $fleetBooking->created_by_admin; // Assuming this field exists to track creation by admin

        // Approve the booking
        $fleetBooking->approved = true;
        $fleetBooking->sent_approval = false;
        $fleetBooking->save();

        // Check if the booking was not created by admin and if the email has not been sent yet
        if (!$isCreatedByAdmin && !$fleetBooking->email_sent) {
            $agentInfo = User::where('id', $fleetBooking->user_id)->first(['email', 'first_name']);
            $agentEmail = $agentInfo->email;
            $agentName = $agentInfo->first_name; // Get the agent's name

            // Send the booking approval email to the agent
            // Mail::to($agentEmail)->send(new BookingApproved($fleetBooking, $agentName));

            $mailInstance = new BookingApproved($fleetBooking, $agentName);
            SendEmailJob::dispatch($agentEmail, $mailInstance);

            // Mark the email as sent
            $fleetBooking->email_sent = true;
            $fleetBooking->save();
        }

        return redirect()->route('booking.details', ['id' => $fleetBooking->id])
            ->with('success', 'Booking Approved successfully.');
    }


    public function unapprove($id)
    {
        // Fetch the fleet booking or fail if not found
        $fleetBooking = FleetBooking::findOrFail($id);
        $fromLocation = Location::where('id', $fleetBooking->from_location_id)->value('name');
        $toLocation = Location::where('id', $fleetBooking->to_location_id)->value('name');
        // dd($fromLocation);
        // Determine if the booking was created by admin
        $isCancelByAdmin = $fleetBooking->created_by_admin;

        // Cancel the related booking in the Bookings table
        $booking = Booking::where('booking_type_id', $fleetBooking->id)->whereIn('booking_type', ['transfer'])->first();
        if ($booking) {
            $booking->update(['booking_status' => 'cancelled']);
            $fleetBooking->approved = false;
            $fleetBooking->save();
        }

        // Notify the agent if the booking was not canceled by admin and if email hasn't been sent
        if (!$isCancelByAdmin) {
            $agentInfo = User::find($fleetBooking->user_id, ['email', 'first_name']);

            if ($agentInfo) {
                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name;
                $amountRefunded = null;
                $location = null;
                // Send booking cancellation email to the agent
                $bookingDate = convertToUserTimeZone($booking->booking_date);
                $mailInstance = new BookingCancel($fleetBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $admin = new BookingCancel(
                    $fleetBooking,
                    'Admin',
                    $fromLocation,
                    $toLocation,
                    $bookingDate,
                    $location,
                    $booking->booking_type,
                    null
                );
                $adminEmails = [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }
                // Mark the email as sent to avoid duplicate notifications
                $fleetBooking->email_sent = true;
                $fleetBooking->save();
            }
        }

        return redirect()->route('mybookings.details', ['id' => $fleetBooking->booking_id])
            ->with('success', 'Booking canceled successfully.');
    }


    public function viewDetails($id)
    {
        // Load cancellation booking policies dynamically
        $cancellation = CancellationPolicies::where('active', 1)->get();
        // Fetch the booking details by ID
        $booking = FleetBooking::with(['driver', 'fromLocation.country'])->where('booking_id', $id)->first();
        $nationality = Country::where('id', $booking->nationality_id)->value('name');
        $currency = $booking->currency;
        $bookingStatus = Booking::where('id', $id)->first();
        $user = User::where('id', $booking->user_id)->first();
        $createdBy = (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Admin';
        $transferHotels = TransferHotel::where('booking_id', $booking->id)->get();

        // Extract names from the records
        $pickupHotelName = $transferHotels->first()->pickup_hotel_name ?? 'N/A';
        $returnDropoffHotelName = $transferHotels->first()->return_dropoff_hotel_name ?? 'N/A';

        $dropoffHotelName = $transferHotels->skip(1)->first()->dropoff_hotel_name ?? 'N/A';

        $returnPickupHotelName = $transferHotels->skip(1)->first()->return_pickup_hotel_name ?? 'N/A';

        // Handle cases with only one record
        if ($transferHotels->count() === 1) {
            $dropoffHotelName = $transferHotels->first()->dropoff_hotel_name ?? 'N/A';
            $returnPickupHotelName = $transferHotels->first()->return_pickup_hotel_name ?? 'N/A';
        }

        // Assign to/from locations based on these values
        $toLocation = $dropoffHotelName !== 'N/A'
            ? $dropoffHotelName
            : Location::where('id', $booking->to_location_id)->value('name');


        $fromLocation = $pickupHotelName !== 'N/A'
            ? $pickupHotelName
            : Location::where('id', $booking->from_location_id)->value('name');

        $to_location = Location::where('id', $booking->to_location_id)->value('name');
        $from_location = Location::where('id', $booking->from_location_id)->value('name');

        $returnPickupHotel = $returnPickupHotelName;
        $returnDropoffHotel = $returnDropoffHotelName;

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $meeting_point_desc = MeetingPoint::where('id', $booking->meeting_point)->value('meeting_point_desc');

        $cancellationBooking = $cancellation->filter(function ($policy) use ($bookingStatus) {
            return $policy->type == $bookingStatus->booking_type;
        });

        $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $bookingStatus->service_date); // Replace with your target date and time
        // Get the current date and time
        $currentDate = Carbon::now();
        // Calculate the difference in days
        $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        $user = auth()->user();
        $cancellationPolicy = CancellationPolicies::where('active', 1)->where('type', 'transfer')->first();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_edit = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('update booking'));
        $can_delete = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('delete booking'));

        // Return the view with booking details
        return view('web.agent.booking_details', compact(
            'booking',
            'countries',
            'nationality',
            'fromLocation',
            'toLocation',
            'returnDropoffHotel',
            'returnPickupHotel',
            'currency',
            'createdBy',
            'bookingStatus',
            'meeting_point_desc',
            'cancellationBooking', // Pass the filtered cancellation policies
            'remainingDays',
            'to_location',
            'from_location',
            'can_edit',
            'can_delete',
            'cancellationPolicy'
        ));
    }


    public function meetingPoint($fromLocationId)
    {
        $fromLocation = Location::find($fromLocationId);

        // Check if the location type is 'airport' and retrieve meeting points if so
        $meetingPoints = [];
        if ($fromLocation && $fromLocation->location_type === 'airport') {
        }

        return $meetingPoints;
    }

    public function transferBookingSubmission($id, $pick_date, $pick_time, $vehicle_make, $vehicle_model, $currency, $rate)
    {

        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }

        $data = Rate::with('fromLocation')->where('id', $id)->first();
        $currentTime = Carbon::createFromFormat('H:i', $pick_time)->format('H:i:s');

        $surcharge = Surcharge::where('country_id', $data->fromLocation->country_id)
            ->where(function ($query) use ($currentTime) {
                $query->where(function ($subQuery) use ($currentTime) {
                    // Case 1: start_time <= end_time (same-day range)
                    $subQuery->whereRaw("'" . $currentTime . "' BETWEEN start_time AND end_time");
                })
                    ->orWhere(function ($subQuery) use ($currentTime) {
                        // Case 2: start_time > end_time (cross-midnight range)
                        $subQuery->whereRaw("(start_time > end_time AND ('" . $currentTime . "' >= start_time OR '" . $currentTime . "' <= end_time))");
                    });
            })->first();

        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        $booking_rate = $this->applyCurrencyConversion($data->rate, $data->currency, $currency);
        $booking_rate = $this->applySurcharge($booking_rate, $surcharge);
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            // Filter the adjustment rates for transaction_type === 'transfer'
            $transferRates = $adjustmentRate->filter(function ($rate) {
                return $rate->transaction_type === 'transfer';
            });

            foreach ($transferRates as $transferRate) {
                // Pass the individual adjustment rate object
                $booking_rate = $this->applyAdjustment($booking_rate, $transferRate);
            }
        }


        if ($pick_time && $pick_date) {

            // Set the target date and time
            $targetDate = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $pick_date . ' ' . $pick_time . ':00'); // Replace with your target date and time
            // Get the current date and time
            $currentDate = Carbon::now();
            // Calculate the difference in days
            $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        }
        $remainingDays = $remainingDays ?? 0;

        // Combine pick_date and pick_time into a Carbon instance
        $pick_datetime = Carbon::parse("$pick_date $pick_time");
        // Get the current time
        $current_time = Carbon::parse(convertToUserTimeZone(Carbon::now()));
        // Calculate the difference in hours
        $time_diff_in_hours = $current_time->diffInHours($pick_datetime);
        // Determine if the time difference is more than 12 hours
        $is_within_12_hours = $time_diff_in_hours > 0 && $time_diff_in_hours <= 12;

        // Get meeting point names for each terminal
        // $meetingPoints = MeetingPoint::where('location_id', $data->from_location_id)
        //     ->orderBy('terminal') // Ensure terminals are ordered if necessary
        //     ->get();
        $limit = auth()->user()->getEffectiveCreditLimit();

        return view('web.agent.booking', [
            'vehicle' => $data,
            'limit' => $limit,
            // 'meetingPoints' => $meetingPoints,
            'pick_date' => $pick_date,
            'pick_time' => $pick_time,
            'currency' => $currency,
            'rate' => $booking_rate,
            'is_within_12_hours' => $is_within_12_hours,
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            'remainingDays' => $remainingDays,
            'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            'selectedTransport' => [
                'vehicle_make' => $vehicle_make,
                'vehicle_model' => $vehicle_model,
            ],
        ]);
    }

    public function printVoucher($id)
    {
        // Get the booking and related transfer hotel(s)
        $booking = FleetBooking::findOrFail($id);
        $transferHotel = TransferHotel::where('booking_id', $booking->id)
            ->orderByDesc('updated_at')
            ->first();

        // Compare timestamps and pick the latest
        $fleetUpdated = $booking->updated_at;
        $transferUpdated = $transferHotel?->updated_at;

        $latestUpdate = $transferUpdated && $transferUpdated->gt($fleetUpdated)
            ? $transferUpdated
            : $fleetUpdated;

        // Format date for file naming
        $updatedDate = $latestUpdate->format('Ymd');

        // Generate the file name using the created date and booking ID
        if (Auth::user()->type === 'admin') {
            $fileName = 'booking_admin_voucher_' . $updatedDate . '_' . $id . '.pdf';
        } else {

            $fileName = 'booking_voucher_' . $updatedDate . '_' . $id . '.pdf';
        }
        $filePath = public_path('bookings/' . $fileName);

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return redirect()->back()->with('error', 'Voucher not found.');
    }

    public function printInvoice($id)
    {
        // Get the booking and related transfer hotel(s)
        $booking = FleetBooking::findOrFail($id);
        $transferHotel = TransferHotel::where('booking_id', $booking->id)
            ->orderByDesc('updated_at')
            ->first();

        // Compare timestamps and pick the latest
        $fleetUpdated = $booking->updated_at;
        $transferUpdated = $transferHotel?->updated_at;

        $latestUpdate = $transferUpdated && $transferUpdated->gt($fleetUpdated)
            ? $transferUpdated
            : $fleetUpdated;

        // Format date for file naming
        $updatedDate = $latestUpdate->format('Ymd');

        // Generate the file name using the created date and booking ID
        $fileName = 'booking_invoice_' . $updatedDate . '_' . $id . '.pdf';
        $filePath = public_path('bookings/' . $fileName);

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return redirect()->back()->with('error', 'Invoice not found.');
    }

    public function toggleEditForm($id)
    {
        $booking = Booking::findOrFail($id);

        // Check if the form is currently displayed
        if (session()->has('editForm')) {
            // Toggle the session value
            session()->forget('editForm');
        } else {
            // Set the session value to true, showing the edit form
            session(['editForm' => true]);
        }

        // Redirect back to the same page to show the form
        return redirect()->back();
    }

    public function updatePassenger(Request $request, FleetBooking $booking)
    {
        // Validate the incoming data
        $request->validate([
            'passenger_full_name' => 'required|string|max:255',
            'passenger_email_address' => 'nullable|email|max:255',
            'passenger_contact_number' => 'required',
            'nationality_id' => 'required',
            // Add validation rules for other fields
        ]);

        // Update the booking with validated data
        $booking->update([
            'passenger_full_name' => $request->input('passenger_full_name'),
            'passenger_email_address' => $request->input('passenger_email_address'),
            'passenger_contact_number' => $request->input('passenger_contact_number'),
            'nationality_id' => $request->input('nationality_id'),
            // Add other fields as necessary
        ]);

        // Clear the editForm session key
        session()->forget('editForm');

        // Show a success message and redirect back
        return redirect()->route('mybookings.details', ['id' => $booking->booking_id])->with('success', 'Booking details updated successfully.');
    }

    public function updateTransfer(Request $request, FleetBooking $booking)
    {
        // Validate the incoming data
        // $request->validate([
        //     '' => 'required|string|max:255',
        //     'passenger_email_address' => 'nullable|email|max:255',
        //     'passenger_contact_number' => 'required',
        //     'nationality_id' => 'required',
        //     // Add validation rules for other fields
        // ]);

        // Update the booking with validated data
        $booking->update([
            'pickup_address' => $request->input('pickup_address'),
            'depart_flight_number' => $request->input('depart_flight_number'),
            'depart_flight_date' => $request->input('depart_flight_date'),
            'flight_departure_time' => $request->input('flight_departure_time'),
            'arrival_flight_number' => $request->input('arrival_flight_number'),
            'arrival_flight_date' => $request->input('arrival_flight_date'),
            'flight_arrival_time' => $request->input('flight_arrival_time'),
            'dropoff_address' => $request->input('dropoff_address'),
            'return_depart_flight_number' => $request->input('return_depart_flight_number'),
            'return_depart_flight_date' => $request->input('return_depart_flight_date'),
            'return_flight_departure_time' => $request->input('return_flight_departure_time'),
            'return_arrival_flight_number' => $request->input('return_arrival_flight_number'),
            'return_arrival_flight_date' => $request->input('return_arrival_flight_date'),
            'return_flight_arrival_time' => $request->input('return_flight_arrival_time'),
            'return_pickup_date' => $request->input('return_pickup_date'),
            'return_pickup_time' => $request->input('return_pickup_time'),
        ]);

        $transferHotels = TransferHotel::where('booking_id', $booking->id)->get();

        foreach ($transferHotels as $hotel) {
            // Check if this is the row that originally had a dropoff address
            if (!empty($hotel->dropoff_hotel_name) && !empty($request->dropoff_address)) {
                $hotel->update([
                    'dropoff_hotel_name' => $request->dropoff_address,
                ]);
                // break; // Stop after first match
            }

            if (!empty($hotel->pickup_hotel_name) && !empty($request->pickup_address)) {
                $hotel->update([
                    'pickup_hotel_name' => $request->pickup_address,
                ]);
            }

            if (!empty($hotel->return_dropoff_hotel_name) && !empty($request->pickup_address)) {
                $hotel->update([
                    'return_dropoff_hotel_name' => $request->pickup_address,
                ]);
            }

            if (!empty($hotel->return_pickup_hotel_name) && !empty($request->dropoff_address)) {
                $hotel->update([
                    'return_pickup_hotel_name' => $request->dropoff_address,
                ]);
            }
        }

        $this->bookingService->sendVoucherEmail($request, $booking, null, null, 1);

        // Clear the editForm session key
        session()->forget('editForm');

        // Show a success message and redirect back
        return redirect()->route('mybookings.details', ['id' => $booking->booking_id])->with('success', 'Transfer Details Updated!');
    }

    public function redirectToFavourites(Request $request, $id)
    {
        // Validate input data
        $validated = $request->validate([
            'travel_date' => 'required|date',
            'travel_time' => 'required|date_format:H:i',
            'currency' => 'required|string',
        ]);

        // Additional static data
        $vehicleMake = $request->input('vehicle_make', 'N-A');
        $vehicleModel = $request->input('vehicle_model', 'N-A');
        $rate = $request->input('rate');

        // Build redirect URL
        return redirect()->route('transferBooking.favourites', [
            'id' => $id,
            'pick_date' => $validated['travel_date'],
            'pick_time' => $validated['travel_time'],
            'vehicle_make' => $vehicleMake ?? 'N-A',
            'vehicle_model' => $vehicleModel ?? 'N-A',
            'currency' => $validated['currency'],
            'rate' => $rate,
        ]);
    }

       public function storeVoucherRedemptions($voucher, $user, $booking_id, $discountPrice)
    {
        DB::beginTransaction();
        try {
            // Increase voucher global used count
            $voucher->increment('used_count');

            // Update or create user voucher usage
            $userVoucher = DiscountVoucherUser::firstOrNew([
                'user_id' => $user->id,
                'voucher_id' => $voucher->id,
            ]);

            $userVoucher->usage_count = ($userVoucher->usage_count ?? 0) + 1;
            $userVoucher->assigned_at = $userVoucher->assigned_at ?? now();
            $userVoucher->save();

            // Save redemption record
            VoucherRedemption::updateOrCreate([
                'user_id' => $user->id,
                'voucher_id' => $voucher->id,
                'booking_id' => $booking_id,
                'discount_amount' => round($discountPrice, 2),
                'redeemed_at' => now(),
            ]);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to apply voucher. Please try again.');
        }
    }
}
