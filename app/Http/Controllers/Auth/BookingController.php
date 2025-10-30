<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\BookingApproved;
use App\Mail\BookingCancel;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Models\CancellationPolicies;
use App\Models\City;
use App\Models\Company;
use App\Models\Configuration;
use App\Models\Country;
use App\Models\Driver;
use App\Models\FleetBooking;
use App\Models\TransferHotel;
use App\Models\User;
use App\Models\Location;
use App\Models\MeetingPoint;
use App\Models\Rate;
use App\Models\Surcharge;
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
use Illuminate\Support\Facades\Validator;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Jobs\SendEmailJob;

class BookingController extends Controller
{
    protected $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create(Request $request)
    {
        // Call the method from BookingService
        $result = $this->bookingService->getBookingData($request);

        // Use the result as needed
        return view('booking.create', $result);
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

        $showRates = collect();
        $dropoffAddress = $request->input('dropoff_address'); // Get the dropoff address
        $pick_date = $request->input('travel_date');
        $pick_time = $request->input('travel_time') ?? "00:00";
        //From Travel location
        $currency = $request->has('currency') ? $request->input('currency') : null;

        //Dropoff location
        $toLocationId = null;
        $fromLocationId = null;
        $dropLocationName = $request->has('dropoff_location') && is_array($request->input('dropoff_location')) ? $request->input('dropoff_location')['name'] : null;


        if (!is_null($dropLocationName)) {
            $toLocationId = Location::where('name', $dropLocationName)->value('id');
        }

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

                        $toLocationName = $request->input('dropoff_address')['name'];
                        $toLocationId = Location::where('name', $toLocationName)->value('id');
                        $showRates = Rate::where('to_location_id', $toLocationId);
                        if ($request->input('pickup_type') == 'airport') {
                            $fromLocationName = $request->input('pickup_address')['name'];
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

                $showRates = $showRates->with('toLocation', 'fromLocation', 'transport')->paginate(5);
                $showRates->appends($request->query());
                $currentTime = Carbon::now()->format('Y-m-d H:i:s');
                $adjustmentRate = AgentPricingAdjustment::where('agent_id', auth()->id())->where('transaction_type', 'transfer')->where('active', 1)->where('effective_date', '<', $currentTime)->where('expiration_date', '>', $currentTime)
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

        try {
            //    dd($showRates);
            //            $html = view('booking.partials.rate_list', [
            //                'showRates' => $showRates,
            //                'dropoffAddress' => $dropoffAddress,
            //                'pick_date' => $pick_date,
            //                'pick_time' => $pick_time,
            //                'fromLocationId' => $fromLocationId,
            //                'remainingDays' => 0,
            //                'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            //                'toLocationId' => $toLocationId,
            //                'toLocationValues' => $showRates->pluck('toLocation')->unique(),
            //                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            //                'meetingPoints' => '',
            //            ])->render();

            return response()->json([
                'showRates' => $showRates,
                'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('booking.index', [
            'bookings' => new BookingTableConfigurator(),

        ]);
    }

    public function store(Request $request)
    {

        // Validate the fleet booking form
        $request->validate($this->fleetBookingFormValidation($request));
        $data = $request->all();
        $subtotal = 0;

        $request = new Request($data);
        // Retrieve location names
        // Fetch flight details for both departure and arrival

        $rateType = Rate::where('id', $request->input('rate_id'))->first();
        $from_location_name = Location::where('id', $rateType->from_location_id)->value('name');
        $to_location_name = Location::where('id', $rateType->to_location_id)->value('name');

        // if ($rateType->rate_type == 'airport_transfer' && (strpos(strtolower($from_location_name), 'airport') !== false || strpos(strtolower($to_location_name), 'airport') !== false)) {
        //     // $flightDetails = $this->bookingService->getFlightDetails($request);
        //     // Ensure successful API response
        //     // if ($flightDetails->getData()->success) {
        //         // Extract flight data
        //         $departFlightData = $request->input('depart_flight_data');
        //         $returnDepartFlightData = $request->input('return_depart_flight_data');
        //         $arrivalFlightData = $request->input('arrival_flight_data');
        //         $returnArrivalFlightData = $request->input('return_arrival_flight_data');
        //         // Initialize variables for flight times
        //         $flightDepartureTime = null;
        //         $returnFlightDepartureTime = null;
        //         $flightArrivalTime = null;
        //         $returnFlightArrivalTime = null;

        //         // Extract times from available data
        //         if ($departFlightData && is_array($departFlightData)) {
        //             // Access the departure time correctly
        //             $flightDepartureTime = $departFlightData[0]->flightPoints[0]->departure->timings[0]->value ?? null; // Use object access
        //         }

        //         if ($arrivalFlightData && is_array($arrivalFlightData)) {
        //             $flightArrivalTime = $arrivalFlightData[0]->flightPoints[1]->arrival->timings[0]->value ?? null;
        //         }
        //         if ($returnDepartFlightData && is_array($returnDepartFlightData)) {
        //             $returnFlightDepartureTime = $returnDepartFlightData[0]->flightPoints[0]->departure->timings[0]->value ?? null;
        //         }


        //         if ($returnArrivalFlightData && is_array($returnArrivalFlightData)) {
        //             $returnFlightArrivalTime = $returnArrivalFlightData[0]->flightPoints[1]->arrival->timings[0]->value ?? null;
        //         }

        //         // Check if each flight input exists, so errors are based only on provided input values
        //         $missingSegments = [];

        //         if ($request->input('flight_departure_time') && !$flightDepartureTime) {
        //             $missingSegments[] = 'departure';
        //         }

        //         if ($request->input('flight_arrival_time') && !$flightArrivalTime) {
        //             $missingSegments[] = 'arrival';
        //         }

        //         if ($request->input('return_flight_departure_time') && !$returnFlightDepartureTime) {
        //             $missingSegments[] = 'return departure';
        //         }

        //         if ($request->input('return_flight_arrival_time') && !$returnFlightArrivalTime) {
        //             $missingSegments[] = 'return arrival';
        //         }

        //         // Generate appropriate error messages based on missing segments
        //         if (count($missingSegments) > 0) {
        //             $message = 'No matching flight details found for: ' . implode(', ', $missingSegments);
        //             return response()->json([
        //                 'success' => false,
        //                 'error' => $message,
        //             ], 400); // Return HTTP 400 for a bad request
        //         }
        //     // } else {
        //     //     $message = 'No matching flight details found';
        //     //     return response()->json([
        //     //         'success' => false,
        //     //         'error' => $message,
        //     //     ], 400); // Return HTTP 400 for a bad request
        //     // }
        // }


        //Booking data
        // Prepare fleet booking data and save
        $fleetBookingData = $this->fleetData($request);
        // Get the base price and route type
        $basePrice = $request->input('booking_cost');
        $booking_status = 'confirmed';
        $payment_type = 'pay_later';
        // Retrieve the Rate based on relevant criteria
        $rate = Rate::where('id', $request->input('rate_id'))->firstOrFail();
        // Get journey type from request and calculate the total booking cost
        $booking_currency = $request->input('currency') ?? $rate->currency;
        $rateCurrency = $rate->currency ?? $request->input('currency');

        // $returnPrice = $this->bookingService->calculateTotalPrice($basePrice, $rate, $journeyType, $request, $booking_currency, $rateCurrency);
        // $fleetBookingData['booking_cost'] = $returnPrice;
        // Retrieve the rate from the booking data (assuming `rate` is the rate price in $fleetBookingData)
        if ($rate->journey_type === 'two_way' || $request->input('journey_type') === 'two_way') {
            $ratePrice = $fleetBookingData['booking_cost'] * 2;
        } else {
            $ratePrice = $fleetBookingData['booking_cost'] ?? 0;
        }
        $subtotal = $ratePrice;
        // $rateCurrency = $request->input('currency') ?? $rate->currency;
        // Call the deductCredit method to handle credit deduction for the agent
        $user = auth()->user();
        if ($request->pay_offline) {
            $deductionResult = $this->bookingService->deductCredit($user, $ratePrice, $booking_currency);
            if ($deductionResult !== true) {
                Toast::title('Insufficient credit limit to create booking.')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);

                return Redirect::back()->withErrors(['error' => 'Insufficient credit limit to create booking.']);
            }
            $booking_status = 'vouchered';
            $payment_type = 'wallet';
        }

        if ($request->pay_online) {

            $booking_status = 'confirmed';
            $payment_type = 'card';
            
        }

        // Ensure both variables are defined
        // $flightDepartureTime = $flightDepartureTime ?? null;
        // $returnFlightDepartureTime = $returnFlightDepartureTime ?? null;
        // $flightArrivalTime = $flightArrivalTime ?? null;
        // $returnArrivalDepartureTime = $returnArrivalDepartureTime ?? null;

        // if ($rateType->rate_type == 'airport_transfer' && (strpos(strtolower($from_location_name), 'airport') !== false || strpos(strtolower($to_location_name), 'airport') !== false)) {
        //     // Assign flight departure and arrival times based on availability
        //     if ($flightDepartureTime && $flightArrivalTime && $returnFlightDepartureTime && $returnArrivalDepartureTime) {
        //         // If both times are available, assign both
        //         $fleetBookingData['flight_departure_time'] = $flightDepartureTime;
        //         $fleetBookingData['flight_arrival_time'] = $flightArrivalTime;
        //         $fleetBookingData['return_flight_departure_time'] = $returnFlightDepartureTime;
        //         $fleetBookingData['return_flight_arrival_time'] = $returnFlightArrivalTime;
        //     }
        //     // Assign times only if they are available
        //     if ($flightDepartureTime) {
        //         $fleetBookingData['flight_departure_time'] = $flightDepartureTime;
        //     }

        //     if ($flightArrivalTime) {
        //         $fleetBookingData['flight_arrival_time'] = $flightArrivalTime;
        //     }

        //     if ($returnFlightDepartureTime) {
        //         $fleetBookingData['return_flight_departure_time'] = $returnFlightDepartureTime;
        //     }

        //     if ($returnFlightArrivalTime) {
        //         $fleetBookingData['return_flight_arrival_time'] = $returnFlightArrivalTime;
        //     }
        // }

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
            if ($maxDeadlineDay > 0)
                $maxDeadlineDate = $targetDate->subDays($maxDeadlineDay)->format('Y-m-d H:i:s');
            else
                $maxDeadlineDate = $targetDate->format('Y-m-d H:i:s');
        }

        $bookingData = [
            'agent_id' => auth()->id(),
            'user_id' => auth()->id(),
            'booking_date' => now()->format('Y-m-d H:i:s'),
            'amount' => $ratePrice,
            'currency' => $booking_currency,
            'service_date' => $request->input('pick_date') . ' ' . $request->input('pick_time'),
            // 'deadline_date' => $booking_status === 'vouchered' ? now()->format('Y-m-d H:i:s') : $maxDeadlineDate,
            'deadline_date' => $maxDeadlineDate,
            'booking_type' => 'transfer',
            'booking_status' => $booking_status,
            'payment_type' => $payment_type,
            'subtotal' => $subtotal
        ];


        // Create the booking and fleet booking
        try {
            DB::beginTransaction();
            $bookingSaveData = \App\Models\Booking::create($bookingData);

            $fleetBooking = FleetBooking::create($fleetBookingData);
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
                $cleanInputpickup = $pickupLocation['name'] ? preg_replace('/[^A-Za-z0-9 ]/', '', $pickupLocation['name']) : null;

                // Check if return_pickup_address is also present and get its clean name
                $returnPickupLocation = $request->input('pickup_address');
                $cleanInputReturnPickup = $returnPickupLocation && isset($returnPickupLocation['name'])
                    ? preg_replace('/[^A-Za-z0-9 ]/', '', $returnPickupLocation['name'])
                    : null;

                // Check if the booking is 'two_way'
                $isTwoWay = $fleetBooking->journey_type === 'two_way'; // Adjust this condition based on your actual field name and value

                // Prepare the data to attach
                $attachData = [
                    'booking_id' => $fleetBooking->id,
                    'pickup_hotel_name' => $cleanInputpickup,
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
                $cleanInputDropOff = $dropOffLocation['name'] ? preg_replace('/[^A-Za-z0-9 ]/', '', $dropOffLocation['name']) : null;

                // Check if return_pickup_address is also present and get its clean name
                $returnPickupLocation = $request->input('dropoff_address');
                $cleanInputReturnPickup = $returnPickupLocation && isset($returnPickupLocation['name'])
                    ? preg_replace('/[^A-Za-z0-9 ]/', '', $returnPickupLocation['name'])
                    : null;

                // Check if the booking is 'two_way'
                $isTwoWay = $fleetBooking->journey_type === 'two_way'; // Adjust this condition based on your actual field name and value

                // Prepare the data to attach
                $attachData = [
                    'booking_id' => $fleetBooking->id,
                    'dropoff_hotel_name' => $cleanInputDropOff,
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

            return Redirect::back()->withErrors(['error' => $e->getMessage()]);
        }
        // Prepare data for PDF
        $bookingData = $this->bookingService->prepareBookingData($request, $fleetBooking, $dropOffName, $pickUpName, $is_updated = null);
        $passenger_email = $request->input('passenger_email_address');
        $hirerEmail = $user->email;

        if ($request->pay_online) {
            return redirect($this->processPayment($bookingSaveData, $bookingData));
        }
        // Create and send PDF
        $this->bookingService->createBookingPDF($bookingData, $hirerEmail, $request, $fleetBooking);
        return Redirect::route('booking.index')->with('status', 'Booking created with flight details!');
    }


    public function processPayment($bookingSaveData, $bookingData)
    {

        $merchantID = env('RAZER_MERCHANT_ID');
        $verifyKey = env('RAZER_VERIFY_KEY');
        $tax_percent = Configuration::getValue('razerpay', 'tax', 0);
        $subtotal = $bookingSaveData->subtotal;
        $tax_amount = $subtotal * ($tax_percent / 100);
        $priceAfterTax = $subtotal + $tax_amount;
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
        $booking = FleetBooking::with('fromLocation.country')->find($booking);
        $request = request();
        $request->validate($this->fleetBookingFormValidation($request));

        // Get the data you want to update
        $updateData = $this->fleetData($request);

        // Remove the user_id key from the update data if it exists
        unset($updateData['user_id']);

        // Update the booking with the remaining data
        $booking->update($updateData);

        // Success message
        Toast::title('Passenger Information Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);

        session()->forget('editForm');
        return redirect()->back()->with('success', 'Passenger information updated successfully.');
    }

    public function updateDriver($booking)
    {
        $booking = FleetBooking::with('driver','fromLocation.country')->find($booking);
        $request = request();
        $request->validate($this->driverFormValidation($request));

        $driver = Driver::firstOrCreate(
            [
                'name' => $request->input('name'),
                'car_no' => $request->input('car_no'),
                'phone_number' => $request->input('phone_number'),
                'phone_code' => $request->input('phone_code')
            ]
        );

        $booking->update([
            'driver_id' => $driver->id
        ]);

        $dropOffName = null;
        $pickUpName = null;
        $is_updated = null;
        app(BookingService::class)->sendVoucherEmail(request(), $booking, $dropOffName, $pickUpName, $is_updated);
    
        // Success message
        Toast::title('Driver Information Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
    
        session()->forget('editDriverForm');
        return redirect()->back()->with('success', 'Driver information updated successfully.');
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
            "passenger_email_address" => ['nullable',],
            "agent_id" => ['nullable',],
            "booking_date" => ['nullable',],
            "pick_date" => ['required',],
            "dropoff_date" => ['nullable',],
            "rate_id" => ['nullable',],
            "booking_cost" => ['required',],
            "currency" => ['required',],
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

    public function driverFormValidation(Request $request): array
    {
        return [
            "name" => ['required',],
            "car_no" => ['required',],
            "phone_number" => ['required',],
        ];
    }

    /**
     * @param mixed $request
     * @return array
     */
    public function fleetData(mixed $request): array
    {
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
            'pick_time' => $request->pick_time,
            "pick_date" => $request->pick_date,
            "dropoff_date" => $request->dropoff_date,
            "rate_id" => $request->rate_id,
            "booking_cost" => $request->booking_cost,
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
            "user_id" => auth()->user()->id,
            'currency' => $request->currency,
        ];
    }

    public function driverData(mixed $request): array
    {
        return [
            'name' => $request->name,
            'phone_code' => $request->phone_code,
            'phone_number' => $request->phone_number,
            'car_no' => $request->vehicle_no
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

    public function approve($id, Request $request)
    {
          // ✅ Validate request data first
          $validator = Validator::make($request->all(), [
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
        ]);
        if ($validator->fails()) {
            Toast::title($validator->errors()->first())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        // Fetch the booking or fail if not found
        $booking = Booking::where('id', $id)->first();
        $fleetBooking = FleetBooking::with(['driver','fromLocation.country'])->findOrFail($booking->booking_type_id);
        // Determine if the currently authenticated user is an admin
        $isCreatedByAdmin = $fleetBooking->created_by_admin; // Assuming this field exists to track creation by admin

        // Approve the booking
        $fleetBooking->approved = true;
        $fleetBooking->sent_approval = false;
        $booking->booking_status = 'confirmed';
        $booking->save();
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
            $dropOffName = null;
            $pickUpName = null;
            $is_updated = null;
            $deadlineDate = $request->input('date'); // e.g., 2025-04-14
            $deadlineTime = $request->input('time'); // e.g., 13:00

            $booking->deadline_date = Carbon::createFromFormat('Y-m-d H:i', $deadlineDate . ' ' . $deadlineTime)->format('Y-m-d H:i:s');
            $booking->save();
            app(BookingService::class)->sendVoucherEmail(request(), $fleetBooking, $dropOffName, $pickUpName, $is_updated);
            // Mark the email as sent
            $fleetBooking->email_sent = true;
            $fleetBooking->save();
        }

        Toast::title('Booking Approved successfully')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
        return redirect()->back()->with('success', 'Booking Approved successfully.');
    }


    public function unapprove($id)
    {
        // Fetch the fleet booking or fail if not found
        $fleetBooking = FleetBooking::findOrFail($id);
        $fromLocation = Location::where('id', $fleetBooking->from_location_id)->value('name');
        $toLocation = Location::where('id', $fleetBooking->to_location_id)->value('name');
        // Determine if the booking was created by admin
        $isCancelByAdmin = $fleetBooking->created_by_admin;

        // Cancel the related booking in the Bookings table
        $booking = Booking::where('booking_type_id', $fleetBooking->id)->first();
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
                // Mail::to($agentEmail)->send(new BookingCancel($fleetBooking, $agentName));
                $bookingDate = convertToUserTimeZone($booking->booking_date);
                $mailInstance = new BookingCancel($fleetBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $admin = new BookingCancel(
                    $fleetBooking,
                    'Admin',
                    $fromLocation, $toLocation, $bookingDate, $location,
                    $booking->booking_type,
                    null
                );
                $adminEmails =  [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }

                // Mark the email as sent to avoid duplicate notifications
                $fleetBooking->email_sent = true;
                $fleetBooking->save();
            }
        }

        return redirect()->back()->with('success', 'Booking canceled successfully.');
    }


    public function viewDetails($id)
    {
        // Fetch the booking details by ID
        $bookingStatus = Booking::where('id', $id)->first();
        $booking = FleetBooking::with(['driver', 'fromLocation.country'])->where('booking_id', $id)->first();
        // $country_code = $booking->fromLocation->country->iso2;
        $country_code = data_get($booking, 'fromLocation.country.iso2');
        $booking_timezone = getTimezoneAbbreviationFromCountryCode($country_code);

       
        $nationality = Country::where('id', $booking->nationality_id)->value('name');
        $currency = $booking->currency;
        // $bookingStatus = Booking::where('booking_type_id', $booking->id)->first();
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

        $returnPickupHotel = $returnPickupHotelName;
        $returnDropoffHotel = $returnDropoffHotelName;
        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $cancel_booking_route = route('deductionViaService', ['service_id' => $bookingStatus->booking_type_id, 'service_type' => $bookingStatus->booking_type]);
        $fullRefund = route('fullRefund', ['service_id' => $bookingStatus->id, 'service_type' => $bookingStatus->booking_type]);
        $offline_payment = route('offlineTransaction');
        $userWallet = $user->credit_limit_currency.' '.number_format($user->credit_limit, 2);
        // Return the view with booking details
        return view('booking.details', compact('booking', 'userWallet','offline_payment','countries', 'nationality', 'fromLocation', 'toLocation', 'returnDropoffHotel', 'returnPickupHotel', 'currency', 'createdBy', 'bookingStatus', 'fullRefund', 'cancel_booking_route', 'booking_timezone'));
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

        $data = Rate::where('id', $id)->first();

        if ($pick_time && $pick_date) {

            // Set the target date and time
            $targetDate = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $pick_date . ' ' . $pick_time . ':00'); // Replace with your target date and time
            // Get the current date and time
            $currentDate = Carbon::now();
            // Calculate the difference in days
            $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        }
        $remainingDays = $remainingDays ?? 0;

        return view('booking.partials.booking_form', [
            'vehicle' => $data,
            'pick_date' => $pick_date,
            'pick_time' => $pick_time,
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first(),
            'remainingDays' => $remainingDays,
            'currency' => $currency,
            'rate' => $rate,
            'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            'selectedTransport' => [
                'vehicle_make' => $vehicle_make,
                'vehicle_model' => $vehicle_model,
            ],
        ]);
    }

    public function printVoucher($id)
    {
        // Retrieve the booking by its ID
        $booking = FleetBooking::findOrFail($id);
        // Get the created_at date and format it as Ymd (e.g., 20241030)
        $createdDate = $booking->created_at->format('Ymd');

        // Generate the file name using the created date and booking ID
        if(Auth::user()->type === 'admin'){
            $fileName = 'booking_admin_voucher_' . $createdDate . '_' . $id . '.pdf';
        }else{

            $fileName = 'booking_voucher_' . $createdDate . '_' . $id . '.pdf';
        }
        $filePath = public_path('bookings/' . $fileName);

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return redirect()->back()->with('error', 'Voucher not found.');
    }

    public function printInvoice($id)
    {
        // Retrieve the booking by its ID
        $booking = FleetBooking::findOrFail($id);
        // Get the created_at date and format it as Ymd (e.g., 20241030)
        $createdDate = $booking->created_at->format('Ymd');

        // Generate the file name using the created date and booking ID
        // if(Auth::user()->type === 'admin'){
        //     $fileName = 'booking_admin_voucher_' . $createdDate . '_' . $id . '.pdf';
        // }else{

            $fileName = 'booking_invoice_' . $createdDate . '_' . $id . '.pdf';
        // }
        $filePath = public_path('bookings/' . $fileName);

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return redirect()->back()->with('error', 'Voucher not found.');
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

    public function toggleEditDriverForm($id)
    {
        $booking = Booking::findOrFail($id);

        // Check if the form is currently displayed
        if (session()->has('editDriverForm')) {
            // Toggle the session value
            session()->forget('editDriverForm');
        } else {
            // Set the session value to true, showing the edit form
            session(['editDriverForm' => true]);
        }

        // Redirect back to the same page to show the form
        return redirect()->back();
    }
}
