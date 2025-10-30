<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\BookingCancel;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Models\Country;
use App\Models\GentingHotel;
use App\Models\GentingRate;
use App\Models\GentingSurcharge;
use App\Models\Hotel;
use App\Models\HotelBooking;
use App\Models\HotelRate;
use App\Models\HotelRoomDetail;
use App\Models\HotelRoomPassengerDetail;
use App\Models\User;
use App\Services\BookingService;
use App\Services\GentingService;
use App\Services\HotelService;
use App\Services\RezliveHotelService;
use App\Http\Requests\HotelSearchRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use ProtoneMedia\Splade\Facades\Toast;

class AgentHotelController extends Controller
{
    protected $hotelService;
    protected $rezliveHotelService;
    protected $bookingService;

    public function __construct(HotelService $hotelService, RezliveHotelService $rezliveHotelService, BookingService $bookingService)
    {
        $this->hotelService = $hotelService;
        $this->rezliveHotelService = $rezliveHotelService;
        $this->bookingService = $bookingService;
    }

    public function index()
    {
        return view("web.hotel.dashboard");
    }

    public function index1()
    {
        try {
            $check_in = Carbon::now()->addDays(30); // today
            $check_out = Carbon::now()->addDay(32); // tomorrow
            $user = auth()->user();
            $currency = $user->credit_limit_currency ?? 'MYR';
            $id = ['112640', '540816', '644394'];
            $data = [
                'id' => $id,
                'check_in' => $check_in->format('d/m/Y'),
                'check_out' => $check_out->format('d/m/Y'),
                'country_code' => 'MY',
                'city_id' => '458',
                'guest_nationality' => 'PK',
                'adults' => ['1' => 1],
                'children' => 0,
                'rooms' => 1,
                'currency' => $currency
            ];
            $hotelById = $this->rezliveHotelService->searchById($data);
            // Check if API returned an error
            if (isset($hotelById->error)) {
                $hotelsArray = ['Hotel' => []]; // Empty array if error
                session()->flash('error', (string) $hotelById->error); // Flash error message
            } else {
                $hotelsArray = json_decode(json_encode($hotelById->Hotels), true);
            }

            // $hotelsArray = json_decode(json_encode($hotelById->Hotels), true);
            return view('web.hotel.hotel_dashboard', ['hotel' => $hotelsArray]);

        } catch (Exception $e) {
            Log::error('Hotel Search Error: ' . $e->getMessage());
            return back()->with('error', 'Failed to fetch hotels: ' . $e->getMessage());
        }
    }

    public function fetchlist(HotelSearchRequest $request)
    {
        // dd($request->all());

        try {

            $validated = $request->validated();
            $cityId = $request->input('city')['id'];
            $cityRezliveId = $request->input('city')['rezliveid'];
            $cityTboId = $request->input('city')['tboid'];
            $currency = $validated['currency'];
            $cityName = $validated['city']['name'];
            $cityCountryCode = $validated['city']['country_code'];
            $nationality = $validated['country']['country_code'];
            [$checkIn, $checkout] = explode(' to ', $validated['check_in_out']);
            $adults = $validated['adult_capacity'];
            $children = $validated['child_capacity'];
            $childAges = $request->input('child_ages') ?? [];
            $rooms = $validated['rooms'] ?? 1;
            $infants = $request->input('infant_capacity') ?? 0;
            $xmlResponse = $this->rezliveHotelService->search([
                'city_code' => $cityRezliveId,
                'country_code' => $cityCountryCode,
                'check_in' => $checkIn,
                'check_out' => $checkout,
                'adults' => $adults,
                'children' => $children,
                'infants' => $infants,
                'child_age' => $childAges,
                'nationality' => $nationality,
                'rooms' => $rooms,
                'currency' => $currency
            ]);

            return $this->prepareResponse($request, $xmlResponse);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    private function prepareResponse(Request $request, $xmlResponse)
    {
        if ($request->ajax()) {
            return response()->json([
                'html' => view('web.hotel.search', [
                    'hotels' => $xmlResponse,
                    'cancellationPolicy' => CancellationPolicies::getActivePolicyByType('transfer'),
                    'currency' => $currency,
                    'parameters' => $parameters,
                ])->render(),
                'next_page' => $nextPageUrl,
            ]);
        }

        return view('web.hotel.search', [
            'cancellationPolicy' => [],
            'hotels' => $xmlResponse,
            'haveData' => request()->all()
        ]);
    }
    public function hotelView(Request $request, $id, $check_in_out, $currency, $rooms, $nationality, $adult_capacity, $child_capacity, $child_ages, $city_id, $country_code)
    {
        try {
            $user = auth()->user();
            $canCreate = $user->type !== 'staff' || in_array($user->agent_code, User::where('type', 'admin')->pluck('agent_code')->toArray()) || Gate::allows('create booking');
            $hotelDetails = $this->rezliveHotelService->getHotelDetails($id);
            if (!$hotelDetails) {
                return redirect()->back()->with('error', 'Hotel not found');
            }
            $totalAdults = 0;
            $totalChildren = 0;
            $roomCount = (int) $rooms; // "1", "2", etc.
            // Decode JSON to associative arrays
            $adultCapacities = json_decode($adult_capacity, true);
            $childCapacities = json_decode($child_capacity, true);
            $childAges = json_decode($child_ages, true);

            // Calculate totals
            $totalAdults = array_sum($adultCapacities ?? []);
            $totalChildren = array_sum($childCapacities ?? []);

            [$check_in, $check_out] = explode(' to ', $check_in_out);
            $data = [
                'id' => $id,
                'check_in' => Carbon::parse($check_in)->format('d/m/Y'),
                'check_out' => Carbon::parse($check_out)->format('d/m/Y'),
                'country_code' => $country_code,
                'city_id' => $city_id,
                'guest_nationality' => $nationality,
                'adults' => $adultCapacities,
                'children' => $childCapacities,
                'child_ages' => $childAges,
                'rooms' => $roomCount
            ];

            $hotelById = $this->rezliveHotelService->searchById($data);
   
            if ($hotelById->error || !empty((string) $hotelById->Hotels->Error)) {

                $errorMessage = trim((string) ($hotelById->error ?? $hotelById->Hotels->Error));
                
                Toast::title($errorMessage)
                ->danger()
                ->rightTop()
                ->autoDismiss(5);

               return redirect()->back()->with('error', $errorMessage ?? 'Something went wrong with the data you provided');

            }

            foreach ($hotelById->Hotels->Hotel as $hotel) {
                foreach ($hotel->RoomDetails->RoomDetail as $roomDetail) {
                    $originalRate = (string) $roomDetail->TotalRate;

                    if (str_contains($originalRate, '|')) {
                        $rates = explode('|', $originalRate);
                        $convertedRates = [];

                        foreach ($rates as $rate) {
                            $converted = $this->rezliveHotelService->applyCurrencyConversion((float) $rate, $hotelById->Currency, $currency);
                            $converted = $this->rezliveHotelService->adjustHotel((float) $converted, [], 'hotelView');
                            $convertedRates[] = number_format($converted, 2);

                        }
                        $roomDetail->ConvertedTotalRate = implode('|', $convertedRates);
                    } else {
                        $converted = $this->rezliveHotelService->applyCurrencyConversion((float) $originalRate, $hotelById->Currency, $currency);
                        $converted = $this->rezliveHotelService->adjustHotel((float) $converted, [], 'hotelView');
                        $roomDetail->ConvertedTotalRate = number_format($converted, 2);
                    }
                }
            }

            $hotelRoomDetails = $hotelById->Hotels->Hotel->RoomDetails;
            $location = $hotelDetails['Hotels']['Location'] ?? null;

            $safeLocation = is_string($location) && !empty($location) ? $location : 'N/A';
            $amenities = $hotelDetails['Hotels']['HotelAmenities'];

            if (is_string($amenities)) {
                $facilities = explode(',', $amenities);
            } elseif (is_array($amenities)) {
                $facilities = $amenities; // already an array, use as-is
            } else {
                $facilities = []; // fallback if it's neither
            }
            $rawParagraph = $hotelDetails['Hotels']['Description'] ?? '';
            $paragraph = is_array($rawParagraph) ? implode(' ', $rawParagraph) : $rawParagraph;

            $description = (string) $hotelRoomDetails->RoomDetail->RoomDescription;
            if (!empty($description)) {
                $roomAmenities = array_map('trim', explode(',', $description));
            } else {
                $roomAmenities = [];
            }
            return view('web.hotel.hotel_view', [
                'data' => $hotelById,
                'session_id' => $hotelById->SearchSessionId,
                'hotelDetails' => $hotelDetails,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'rooms' => $rooms,
                'child_capacity' => $childCapacities,
                'child_ages' => $childAges,
                'adult_capacity' => $adultCapacities,
                'currency' => $currency,
                'facilities' => $facilities,
                'paragraph' => $paragraph,
                'roomAmenities' => $roomAmenities,
                // 'listItems' => $listItems,
                'booking_date' => $check_in,
                // 'booking_slot' => $selectedSlot,
                'countries' => Country::pluck('name', 'id'),
                'canCreate' => $canCreate,
                'nationality' => $nationality,
                'country_code' => $country_code,
                'city_id' => $city_id,
                'hotel_location' => $safeLocation
            ]);
        } catch (\Throwable $th) {
            // Log the error for debugging
            \Log::error('Rezlive Hotel View Error: ' . $th->getMessage());
                
                Toast::title($th->getMessage())
                ->danger()
                ->rightTop()
                ->autoDismiss(5);
            // Redirect back with a toastr-style error message
            return redirect()->back()->with('error', 'Unable to fetch hotel data at the moment. Please try again later.');
        }

    }

    public function hotelPreBooking(Request $request, $hotel_id, $booking_key, $session_id)
    {
        // $roomDetails = json_decode($room_details, true);
        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate(auth()->id());
        // Check if child_ages is "N/A", and if so, treat it as an empty array
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('create booking')) {
            abort(403, 'This action is unauthorized.');
        }
        $totalAdults = 0;
        $totalChildren = 0;
        $roomCount = (int) $request->rooms; // "1", "2", etc.
        // Decode JSON to associative arrays
        $adultCapacities = $request->adult_capacity;
        $childCapacities = $request->child_capacity;
        $childAges = $request->child_ages;
        // Calculate totals
        $totalAdults = array_sum($adultCapacities ?? []);
        $totalChildren = array_sum($childCapacities ?? []);
        // $data = [
        //     'id' => $hotel_id,
        //     'check_in' => Carbon::parse($check_in)->format('d/m/Y'),
        //     'check_out' => Carbon::parse($check_out)->format('d/m/Y'),
        //     'country_code' => $country_code,
        //     'city_id' => $city_id,
        //     'guest_nationality' => $nationality,
        //     'adults' => $adultCapacities,
        //     'children' => $childCapacities,
        //     'child_ages' => $childAges,
        //     'rooms' => $roomCount
        // ];
        // $hotelById = $this->rezliveHotelService->searchById($data);
        // if ($hotelById->error || !empty((string) $hotelById->Hotels->Error)) {
        //     return redirect()->back()->with('error', 'Something Wrong with the data you provided');
        // }
        // $hotelRoomDetails = $hotelById->Hotels->Hotel->RoomDetails;

        $roomData = [];

        $roomRange = (int) $request->rooms;
        $roomNumbers = range(1, $roomRange);
        $childAges = is_string($request->child_ages) ? json_decode($request->child_ages, true) : $request->child_ages;
        $childAges = is_array($childAges) ? $childAges : [];

        foreach ($roomNumbers as $roomNumber) {
            $roomData[] = [
                'room_number' => $roomNumber,
                'adult_capacity' => $adultCapacities[$roomNumber] ?? "0",
                'child_capacity' => $childCapacities[$roomNumber] ?? "0",
                'child_ages' => isset($childAges[$roomNumber]) && is_array($childAges[$roomNumber])
                    ? array_values($childAges[$roomNumber])
                    : [],
            ];
        }
        $rawPrices = explode('|', $request->price[0]);
        $price = array_map(function ($val) {
            return (float) str_replace(',', '', $val);
        }, $rawPrices);
        // If $price is an array with multiple values, sum them
        if (count($price) > 1) {
            $price = array_sum($price); // Sum the values in the array
        } else {
            // If $price is a single value, keep it as is
            $price = $price[0];
        }
        return view(
            'web.hotel.pre_booking',
            [
                // 'gentingHotels' => $gentingHotels,
                'hotel_id' => $hotel_id,
                'hotel_name' => $request->hotel_name[0],
                'booking_key' => $booking_key,
                'session_id' => $session_id,
                'nationality' => $request->guest_nationality,
                'country_code' => $request->country_code,
                'city_id' => $request->city_id,
                'check_in' => $request->check_in,
                'check_out' => $request->check_out,
                'roomDetails' => $roomData,
                'rooms' => $roomCount,
                'child_capacity' => $childCapacities,
                'adult_capacity' => $adultCapacities,
                'child_ages' => $childAges,
                'currency' => $request->currency,
                'price' => $price,
                'actual_price' => $request->actual_price[0],
                'room_type' => $request->room_type[0],
                'location' => $request->location,
                'rez_child_ages' => $request->rez_child_ages,
                // 'facilities' => $facilities,
                // 'entitlements' => $entitlements,
                // 'paragraph' => $paragraph,
                // 'listItems' => $listItems,
                // 'booking_slot' => $selectedTimeSlot,
                'countries' => Country::all(['id', 'name'])->pluck('name', 'id'),
            ]
        );
    }

    public function getCancellationPolicy(Request $request)
    {
        $params = $request->all();

        $cancellationPolicy = $this->rezliveHotelService->getCacellationPolicy([
            'agent_code' => 'CD33410',
            'user_name' => 'Ibrahimaftab',
            'arrival_date' => $params['arrival_date'],
            'departure_date' => $params['departure_date'],
            'hotel_id' => $params['hotel_id'],
            'country_code' => $params['country_code'],
            'city_id' => $params['city_id'],
            'guest_nationality' => $params['guest_nationality'],
            'currency' => $params['currency'],
            'rooms' => [
                [
                    'booking_key' => $params['booking_key'],
                    'adults' => $params['adults'],
                    'children' => $params['children'],
                    'children_ages' => $params['children_ages'],
                    'type' => $params['type'],
                ]
            ],
        ]);

        if ($cancellationPolicy && isset($cancellationPolicy->CancellationInformations)) {
            $html = view('web.hotel.partials.cancellation_policy', ['policies' => $cancellationPolicy->CancellationInformations])->render();
            return response()->json(['success' => true, 'html' => $html]);
        }

        return response()->json(['success' => false]);
    }
    public function preBookingStore(Request $request)
    {
        try {
            $validationData = $this->rezliveHotelService->hotelFormValidationArray($request);
            $rules = $validationData['rules'];
            $messages = $validationData['messages'];

            $validator = Validator::make($request->all(), $rules, $messages);

            // Add duplicate full name check
            $validator->after(function ($validator) use ($request) {
                $seen = [];
                $passengers = $request->input('passengers', []);

                foreach ($passengers as $roomIndex => $roomPassengers) {
                    foreach ($roomPassengers as $passengerIndex => $passenger) {
                        $first = strtolower(trim($passenger['first_name'] ?? ''));
                        $last = strtolower(trim($passenger['last_name'] ?? ''));
                        $fullName = $first . ' ' . $last;

                        if ($first && $last) {
                            if (isset($seen[$fullName])) {
                                $validator->errors()->add(
                                    "passengers.$roomIndex.$passengerIndex.first_name",
                                    "Duplicate name '{$passenger['first_name']} {$passenger['last_name']}' found in Room " . ($roomIndex + 1) . "."
                                );
                            } else {
                                $seen[$fullName] = true;
                            }
                        }
                    }
                }
            });

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $previewResponse = $this->rezliveHotelService->preBookingSubmission($request);
            $preview = json_decode($previewResponse->getContent());

            $bookingId = $preview->booking_id ?? null;
            $hotelBookingId = $preview->hotel_booking_id ?? null;
            $dataToEncrypt = [
                'preBookingCancellation' => $preview->preBookingCancellation ?? null,
                'bookingData' => $preview->bookingData ?? null,
                'preBookingDetails' => $preview->preBookingDetails ?? null,
            ];
            $encryptedData = encrypt($dataToEncrypt);

            $redirectUrl = route('hotel.bookingSummary', [
                'message' => 'Booking preview successful',
                'booking_id' => $bookingId,
                'hotel_booking_id' => $hotelBookingId,
                'preBookingData' => $encryptedData,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking preview successful',
                'redirect_url' => $redirectUrl,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bookingSummary(Request $request)
    {
        $message = $request->query('message');
        $bookingId = $request->query('booking_id');
        $hotelBookingId = $request->query('hotel_booking_id');
        try {
            $preBookingData = decrypt($request->query('preBookingData'));
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', 'Invalid or expired booking data. Please try again.');
        }

        if (!$bookingId || !$hotelBookingId || !$preBookingData) {
            return redirect()->back()->with('error', 'Missing booking preview data.');
        }

        $booking = Booking::find($bookingId);
        $hotelBooking = HotelBooking::find($hotelBookingId);

        return view('web.hotel.hotel_booking', compact('message', 'booking', 'hotelBooking', 'preBookingData'));
    }



    public function store(Request $request)
    {
        try {
            $booking = Booking::find($request->booking_id);
            $hotelBooking = HotelBooking::find($request->hotel_booking_id);
            $hotelRoomDetails = HotelRoomDetail::where('booking_id', $hotelBooking->id)->with('passengers')->get();

            $salutations = [];
            $firstNames = [];
            $lastNames = [];
            $childAgesFlat = [];
            $totalCapacityPerRoom = [];

            $adultCapacities = [];
            $childCapacities = [];
            $childAges = [];

            $guestIndex = 1;

            foreach ($hotelRoomDetails as $roomNo => $guests) {
                // "id" => 1
                // "room_no" => "1"
                // "booking_id" => 11
                // "extra_bed_for_child" => 0
                $adultCount = $guests->number_of_adults ?? 0;
                $childCount = $guests->number_of_children ?? 0;
                $child_ages = json_decode($guests->child_ages) ?? [];
                $childAgeList = [];
                $c = 0;
                foreach ($guests->passengers as $guest) {
                    $salutations[$guestIndex] = $guest->passenger_title;
                    $firstNames[$guestIndex] = $guest->passenger_first_name;
                    $lastNames[$guestIndex] = $guest->passenger_last_name;

                    if ($guest->passenger_title == 'Child') {

                        $childAgesFlat[$guestIndex] = $child_ages[$c];
                        $c++;
                    }

                    $guestIndex++;
                }

                $adultCapacities[$roomNo + 1] = (string) $adultCount;
                $childCapacities[$roomNo + 1] = (string) $childCount;
                $childAges[$roomNo + 1] = $child_ages; // Reset index to 0
                $totalCapacityPerRoom[$roomNo + 1] = $adultCount + $childCount;
            }

            // Now build params:
            $dataArray = [
                'session_id' => $request->session_id,
                'check_in' => $hotelBooking->check_in,
                'check_out' => $hotelBooking->check_out,
                'nationality' => $request->nationality,
                'country_code' => $request->country_code,
                'city' => $request->city_id,
                'hotel_id' => $request->hotel_id,
                'hotel_name' => $hotelBooking->hotel_name,
                'currency' => 'MYR',
                'room_type' => $hotelBooking->room_type,
                'booking_key' => $request->booking_key,
                'adults' => $adultCapacities,
                'children' => $childCapacities,
                'child_ages' => $childAges,
                'rooms' => (int) $hotelBooking->number_of_rooms,
                'price' => $request->total_cost,
                'beforeAfterPrice' => $request->beforeAfterPrice,
                // 'priceDifference' => $request->priceDifference,
                'priceDifference' => 30,
                // Guest info per index
                'title' => $salutations,
                'first_name' => $firstNames,
                'last_name' => $lastNames,
                'child_age' => $childAgesFlat,

                // Required for loop in XML generation
                'totalCapacityPerRoom' => $totalCapacityPerRoom,
            ];

            $bookingPrice = $request->beforeAfterPrice ?? $request->total_cost;

            if (isset($bookingPrice) && (float) $request->priceDifference != 0) {
                $booking->update(['amount' => (float) $bookingPrice]);
                $hotelBooking->update(['total_cost' => (float) $bookingPrice]);
            }
            // dd($dataArray);

            $hotelBookingApi = $this->rezliveHotelService->hotelBooking($dataArray);
            if ((string) $hotelBookingApi->BookingDetails->BookingStatus === 'Fail' || (string) $hotelBookingApi->BookingDetails->BookingStatus === 'Rejected') {
               
                return response()->json([
                    'error' => 'Booking Fail '.$hotelBookingApi->BookingDetails->BookingReason
                ], 400);
            }
            $user = auth()->user();
            // If the payment method is offline, handle credit deduction and booking status update
            if ($request->submitButton === "pay_offline") {
                $deductionResult = $this->bookingService->deductCredit($user, (float) $bookingPrice , $booking->currency);
                if ($deductionResult !== true) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient credit limit to create booking.'
                    ], 400);
                }
                // Update payment type and booking status
                $booking->payment_type = 'wallet'; // Set payment type to wallet
                $booking->booking_status = 'vouchered'; // Update booking status to 'vouchered'
                $booking->save();
                $bookingCode = (string) $hotelBookingApi->BookingDetails->BookingCode;
                $hotelBooking->rezlive_booking_code = $bookingCode;
                $hotelBooking->booking_status = (string) $hotelBookingApi->BookingDetails->BookingStatus;
                $hotelBooking->reservation_id = $bookingCode;
                $hotelBooking->rezlive_booking_id = (string) $hotelBookingApi->BookingDetails->BookingId;
                $hotelBooking->general_remarks = $request->general_remarks;
                $hotelBooking->approved = '1';
                $hotelBooking->save();
                $hotelBooking->confirmation()->create([
                    'confirmation_status'=>'pending',
                ]);
            } else {
                // If payment method is not offline, set payment type to 'card'
                $booking->payment_type = 'card';
                $booking->booking_status = 'vouchered'; // Assuming 'confirmed' is the status when paying with card
                $booking->save();
            }

            $is_updated = null;
            // Prepare data for PDF
            $emailData = $this->rezliveHotelService->prepareBookingData($request, $hotelBooking, $is_updated);
            // $passenger_email = $request->input('passenger_email_address');
            $hirerEmail = $user->email;
            // Create and send PDF
            $this->rezliveHotelService->createBookingPDF($emailData, $hirerEmail, $request, $hotelBooking);

            return response()->json([
                'success' => true,
                'message' => 'Booking successful!',
                'redirect_url' => route('hotelBookings.index')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed Booking.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function viewDetails($id)
    {
        
        $booking = HotelBooking::where('booking_id', $id)->firstOrFail();
        $roomDetails = HotelRoomDetail::where('booking_id', $booking->id)->with('passengers')->get();
        $roomDetails->transform(function ($item) {
            $nationality = Country::where('id', $item->nationality_id)->value('name');
            $item->nationality = $nationality;
            return $item;
        });
        $dataArray = [
            'booking_id' => $booking->rezlive_booking_id,
            'booking_code' => $booking->rezlive_booking_code,
        ];
        $currency = $booking->currency;
        $getBookingDetails = $this->rezliveHotelService->getBookingDetails($dataArray);
        if (!$getBookingDetails['status']) {
            
            Toast::title('Something went wrong!')
            ->danger()
            ->rightTop()
            ->autoDismiss(5);

            return redirect()->back()->with('error',  'Unknown error from booking!');
        }
        $getBookingDetails = $getBookingDetails['data'];

        $cancellationPolicy = $getBookingDetails->Booking->HotelInfo->CancellationPolicy->CancellationPolicyInfo;
        foreach ($cancellationPolicy as $line) {
            // Match charges like "Charges - 152.44 MYR ... After 19 Jul 2025 00:00:00"
            if (preg_match('/Charges\s*-\s*([\d.]+)\s*([A-Z]{3}).*?After\s*(\d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2})/', $line, $matches)) {
                $amount = (float) $matches[1];
                $policy_currency = $matches[2];
                $dateStr = $matches[3];

                $cancelDate = Carbon::createFromFormat('d M Y H:i:s', $dateStr);
                $adjustedDate = $cancelDate->copy()->subDays(2);

                // If adjusted date is past, use today
                $finalDate = $adjustedDate->isPast() ? Carbon::today('Asia/Kolkata') : $adjustedDate;

                // Convert currency (example assumes you have $request->currency and a method)
                $convertedAmount = $this->rezliveHotelService->applyCurrencyConversion($amount, $policy_currency, $currency);
                $param['currency'] = $currency;
                $chargesAmount = $this->rezliveHotelService->adjustHotel($convertedAmount,$param,'hotelView');
                // Reconstruct or annotate sentence
                $processed[] = "Charges - {$chargesAmount} {$currency} Applicable If Cancelled After " . $finalDate->format('d M Y H:i:s') . " Hrs IST";
            } 
            elseif (preg_match('/No Charges Applicable If Cancelled Before (\d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2})/', $line, $matches)) {
                $dateStr = $matches[1];

                $cancelDate = Carbon::createFromFormat('d M Y H:i:s', $dateStr, 'Asia/Kolkata');
                $adjustedDate = $cancelDate->copy()->subDays(2);
                $finalDate = $adjustedDate->isPast() ? Carbon::today('Asia/Kolkata') : $adjustedDate;

                $processed[] = "No Charges Applicable If Cancelled Before " . $finalDate->format('d M Y H:i:s') . " Hrs IST";

            }
            else {
                // Leave non-matching lines unchanged
                $processed[] = (string)$line;
            }
        }
        $cancellationPolicy = $processed;
        // dd($cancellationPolicy, $processed);
        
        $bookingStatus = Booking::where('id', $id)->first();

        $user = User::where('id', $booking->user_id)->first();
        $createdBy = (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Admin';

        // $location = Location::where('id', $booking->location_id)->value('name');

        // $countries = Country::all(['id', 'name'])->pluck('name', 'id');

        // $cancellationBooking = $cancellation->filter(function ($policy) use ($bookingStatus) {
        //     return $policy->type == $bookingStatus->booking_type && $bookingStatus->booking_type !== 'ticket';
        // });

        $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $bookingStatus->service_date);
        // Get the current date and time
        $currentDate = Carbon::now();
        // Calculate the difference in days
        $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_edit = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('update booking'));
        $can_delete = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('delete booking'));
        // Return the view with booking details
        return view('web.hotel.hotelBooking_details', compact(
            'booking',
            'currency',
            'createdBy',
            'bookingStatus',
            // 'cancellationBooking',
            'remainingDays',
            'can_edit',
            'can_delete',
            'roomDetails',
            'cancellationPolicy',
            'getBookingDetails',
        ));
    }

    public function testHotelDetails()
    {
        $hotelId = '292515'; // Replace with a real hotel code from your search response
        $details = $this->rezliveHotelService->getHotelDetails($hotelId);
    }

    private function parseDates($range)
    {
        [$start, $end] = explode(' to ', $range);
        $checkIn = Carbon::parse($start);
        $checkOut = Carbon::parse($end);
        $nights = $checkIn->diffInDays($checkOut);

        return [$checkIn, $checkOut, $nights];
    }

    private function calculateSurcharge($hotelId, $checkIn, $checkOut)
    {
        $surchargeData = GentingSurcharge::where('genting_hotel_id', $hotelId)->value('surcharges');
        if (!$surchargeData)
            return 0;

        $surcharges = json_decode($surchargeData, true);
        $total = 0;
        $weekendDays = collect($surcharges)->filter(fn($s) => $s['surcharge_type'] === 'weekend')
            ->pluck('surcharge_details.weekend')->map(fn($d) => ucfirst(strtolower($d)))->toArray();

        for ($date = $checkIn->copy(); $date->lt($checkOut); $date->addDay()) {
            foreach ($surcharges as $s) {
                $type = $s['surcharge_type'];
                $details = $s['surcharge_details'];
                if ($type === 'weekend' && in_array($date->format('l'), $weekendDays)) {
                    $total += $details['amount'];
                    break; // apply once
                } elseif ($type === 'fixed_date' && $date->isSameDay(Carbon::parse($details['fixed_date']))) {
                    $total += $details['amount'];
                } elseif ($type === 'date_range') {
                    $start = Carbon::parse($details['start_date']);
                    $end = Carbon::parse($details['end_date']);
                    if ($date->between($start, $end)) {
                        $total += $details['amount'];
                    }
                }
            }
        }
        return $total;
    }

    private function extractDescription($description)
    {
        $lines = explode("\n", $description);
        $listItems = array_filter($lines, fn($line) => str_starts_with(trim($line), '*'));
        $paragraph = implode(' ', array_filter($lines, fn($line) => !str_starts_with(trim($line), '*') && !empty(trim($line))));
        return [$paragraph, $listItems];
    }

    public function showBookings(Request $request)
    {
        $user = auth()->user();
        // Retrieve search inputs
        $search = $request->input('search'); // General search (if needed)
        $referenceNo = $request->input('user_id');
        $bookingId = $request->input('booking_unique_id');
        $reservationType = $request->input('booking_status');
        $gentingName = $request->input('hotel_name');
        $package = $request->input('package');
        $check_in = $request->input('check_in');
        $check_out = $request->input('check_out');
        $location = $request->input('location');
        $type = $request->input('type');
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        if ($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('list booking')) {
            abort(403, 'This action is unauthorized.');
        }

        // Query the database with search and filters
        $bookings = HotelBooking::query()
            ->leftJoin('bookings', 'hotel_bookings.booking_id', '=', 'bookings.id') // Join with the bookings table
            // ->leftJoin('locations as location', 'hotel_bookings.location_id', '=', 'location.id') // Join locations for tours
            ->leftJoin('users as agent', 'hotel_bookings.user_id', '=', 'agent.id') // Join booking table with users table
            ->select(
                'hotel_bookings.*',
                'bookings.*', // Select columns from the bookings table
                // 'location.name as location_name', // Location name for tours
                'agent.agent_code' // agent_code from users table
            )
            // Filter based on user type
            ->when($user->type === 'agent', function ($query) use ($user) {
                // Include bookings for the agent and their staff
                $staffIds = User::where('type', 'staff')->where('agent_code', $user->agent_code)->pluck('id');
                return $query->where(function ($subQuery) use ($user, $staffIds) {
                    $subQuery->where('hotel_bookings.user_id', $user->id)
                        ->orWhereIn('hotel_bookings.user_id', $staffIds);
                });
            })
            ->when($user->type === 'staff' && !in_array($user->agent_code, $adminCodes), function ($query) use ($user) {
                return $query->where('hotel_bookings.user_id', $user->id);
            })
            ->when($referenceNo, function ($query, $referenceNo) {
                return $query->where('hotel_bookings.user_id', $referenceNo);
            })
            ->when($bookingId, function ($query, $bookingId) {
                return $query->where('bookings.booking_unique_id', 'like', '%' . $bookingId);
            })
            ->when($reservationType, function ($query, $reservationType) {
                if ($reservationType !== '') {
                    return $query->where('bookings.booking_status', $reservationType); // '1' or '0'
                }
            })
            ->when($gentingName, function ($query, $gentingName) {
                return $query->where('hotel_bookings.hotel_name', 'like', "%{$gentingName}%");
            })
            ->when($package, function ($query, $package) {
                return $query->where('hotel_bookings.package', 'like', "%{$package}%");
            })
            ->when($check_out, function ($query, $check_out) {
                // Ensure time is in the correct format, and compare with `$check_out` field
                return $query->whereDate('hotel_bookings.check_out', '=', $check_out);
            })
            ->when($check_in, function ($query, $check_in) {
                // Ensure time is in the correct format, and compare with `check$check_in` field
                return $query->whereDate('hotel_bookings.check_in', '=', $check_in);
            })
            ->when($location, function ($query, $location) {
                return $query->where('location.name', 'like', "%{$location}%");
            })
            ->when($type, function ($query, $type) {
                return $query->where('hotel_bookings.type', 'like', "%{$type}%");
            })
            ->with(['booking', 'booking.user'])
            ->orderBy('hotel_bookings.id', 'desc')
            ->orderBy('bookings.booking_date', 'desc')
            ->paginate(10)
            ->appends($request->all()); // Retain query inputs in pagination links

        // Total bookings count
        $totalBookings = HotelBooking::count();
        $offline_payment = route('hotelOfflineTransaction');

        return view('web.hotel.hotelBookingList', compact('bookings', 'totalBookings', 'offline_payment'));
    }

    public function showVoucher($id)
    {
        return $this->rezliveHotelService->printVoucher($id);
    }

    public function showInvoice($id)
    {
        return $this->rezliveHotelService->printInvoice($id);
    }

    public function unapprove($id)
    {
        $tourBooking = HotelBooking::findOrFail($id);
        $fromLocation = null;
        $toLocation = null;
        $isCancelByAdmin = $tourBooking->created_by_admin;

        $booking = Booking::where('booking_type_id', $tourBooking->id)->whereIn('booking_type', ['genting_hotel'])->first();
        if ($booking) {
            $booking->update(['booking_status' => 'cancelled']);
            $tourBooking->approved = false;
            $tourBooking->save();
        }

        if (!$isCancelByAdmin) {
            $agentInfo = User::find($tourBooking->user_id, ['email', 'first_name']);

            if ($agentInfo) {
                $agentEmail = $agentInfo->email;
                $agentName = $agentInfo->first_name;
                $amountRefunded = null;
                $location = null;

                $bookingDate = convertToUserTimeZone($booking->booking_date);
                $mailInstance = new BookingCancel($tourBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $booking->booking_type, $amountRefunded);
                SendEmailJob::dispatch($agentEmail, $mailInstance);
                $admin = new BookingCancel(
                    $tourBooking,
                    'Admin',
                    $fromLocation,
                    $toLocation,
                    $bookingDate,
                    $location,
                    $booking->booking_type,
                    $amountRefunded
                );
                $adminEmails = [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];
                foreach ($adminEmails as $adminEmail) {
                    SendEmailJob::dispatch($adminEmail, $admin);
                }
                $tourBooking->email_sent = true;
                $tourBooking->save();
            }
        }

        return redirect()->route('hotelBookings.details', ['id' => $booking->id])
            ->with('success', 'Booking canceled successfully.');
    }

    public function updatePassenger(Request $request, $id, $room_no)
    {
        try {
            // Fetch the booking record
            // $booking = GentingRoomDetail::where('booking_id', $id)->where('room_no', $room_no)->first();
            $passengerDetails = GentingRoomPassengerDetail::where('id', $id)->where('room_detail_id', $room_no)->first();
            // Check if the booking exists
            if (!$passengerDetails) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found.'
                ], 404);
            }
            
            // Call the service to update passenger information
            $booking = $this->gentingService->updatePassenger($request, $passengerDetails);

            // If the request is AJAX, return a JSON response
            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking details updated successfully.',
                    'booking' => $booking
                ]);
            }

            // For non-AJAX (normal form submission), redirect back with success message
            return redirect()->route('gentingBookings.details', ['id' => $booking->booking_id])
                ->with('success', 'Booking details updated successfully.');
        } catch (\Exception $e) {
            // Handle any exceptions and return a JSON error response for AJAX requests
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }

            // For non-AJAX, return the usual error handling (could be a redirect or error message)
            return back()->withErrors(['error' => 'Something went wrong! Please try again later.']);
        }
    }
}
