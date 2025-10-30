<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\HotelSearchRequest;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Models\HotelRoomPassengerDetail;
use App\Models\Country;
use App\Models\HotelBooking;
use App\Models\HotelRoomDetail;
use App\Models\Location;
use App\Models\User;
use App\Services\BookingService;
use App\Services\HotelService;
use App\Services\RezliveHotelService;
use App\Tables\HotelBookingTableConfigurator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use ProtoneMedia\Splade\Facades\Toast;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Encryption\DecryptException;
use ProtoneMedia\Splade\SpladeTable;

class HotelBookingController extends Controller
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
        return view('hotelBooking.index', [
            'hotel_booking' => new HotelBookingTableConfigurator(),

        ]);
    }

    public function create(Request $request)
    {
        $hotels = [];
        $cancellationPolicy = [];
        $currency = null;
        $parameters = [];
        if ($request->has('city')) {
            // dd($request->all());
            try {
                $validated = app(HotelSearchRequest::class)->validated();
                $cityId = $validated['city']['id'];
                $currency = $validated['currency'];
                $cityCountryCode = $validated['city']['country_code'];
                $nationality = $validated['country']['country_code'];
                [$checkIn, $checkout] = explode(' to ', $validated['check_in_out']);
                $adults = $validated['adult_capacity'];
                $children = $validated['child_capacity'];
                $childAges = $request->input('child_ages') ?? [];
                $rooms = $validated['rooms'] ?? 1;
                $infants = $request->input('infant_capacity') ?? 0;

                $hotels = $this->rezliveHotelService->search([
                    'city_code' => $cityId,
                    'country_code' => $cityCountryCode,
                    'check_in' => $checkIn,
                    'check_out' => $checkout,
                    'adults' => $adults,
                    'children' => $children,
                    'infants' => $infants,
                    'child_age' => $childAges,
                    'nationality' => $nationality,
                    'rooms' => $rooms,
                    'currency' => $currency,
                ]);
                // dd($hotels);
                $parameters = compact(
                    'cityId', 'currency', 'checkIn', 'checkout', 'adults',
                    'children', 'infants', 'childAges', 'rooms', 'nationality'
                );

                $cancellationPolicy = \App\Models\CancellationPolicies::getActivePolicyByType('transfer');

            } catch (\Exception $e) {
                \Log::error('Hotel search error: ' . $e->getMessage());
                return redirect()->back()->withInput()->withErrors(['error' => 'Failed to fetch hotels: ' . $e->getMessage()]);
            }
        }
        return view('hotelBooking.create', [
            'showHotels' => $hotels,
            'cancellationPolicy' => $cancellationPolicy,
            'currency' => $currency,
            'parameters' => $parameters,
            'haveData' => $request->all(),
        ]);
    }

    

    public function hotelView(Request $request, $id, $check_in_out, $currency, $rooms, $nationality, $adult_capacity, $child_capacity, $child_ages, $city_id, $country_code)
    {
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
            return redirect()->back()->with('error', 'Something Wrong with the data you provided');
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
        return view('hotelBooking.hotelView', [
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
    }

    public function hotelPreBooking(Request $request, $hotel_id, $booking_key, $session_id)
    {
        // $roomDetails = json_decode($room_details, true);
        // $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate(auth()->id());
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
        $price = explode('|', $request->price[0]);

        // If $price is an array with multiple values, sum them
        if (count($price) > 1) {
            $price = array_sum($price); // Sum the values in the array
        } else {
            // If $price is a single value, keep it as is
            $price = $price[0];
        }
        $countries = Country::pluck('name', 'id');

        $passengers = [];

        foreach ($roomData as $index => $room) {
            $total = ($room['adult_capacity'] ?? 0) + ($room['child_capacity'] ?? 0);

            for ($i = 0; $i < $total; $i++) {
                $passengers[$index][$i] = [
                    'title' => '',
                    'first_name' => '',
                    'last_name' => '',
                    'email_address' => '',
                    'phone_code' => '',
                    'contact_number' => '',
                    'nationality_id' => '',
                ];
            }
        }
        return view(
            'hotelBooking.hotel_pre_booking',
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
                'countries' => $countries,
                'passengers' => $passengers
            ]
        );
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
                Toast::title($validator->errors())
                ->danger()
                ->autoDismiss(10);
                return back()->withErrors($validator)->withInput();
            }

            $previewResponse = $this->rezliveHotelService->preBookingSubmission($request);
            $preview = json_decode($previewResponse->getContent());

            $bookingId = $preview->booking_id ?? null;
            $hotelBookingId = $preview->hotel_booking_id ?? null;
            $dataToEncrypt = [
                'preBookingCancellation' => $preview->preBookingCancellation ?? null,
                'preBookingDetails' => $preview->preBookingDetails ?? null,
                'bookingData' => $preview->bookingData ?? null,
            ];
            $encryptedData = encrypt($dataToEncrypt);
            Toast::title('Booking Preview Successful')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->route(
                'auth.preBookingSummary',
                [
                    'booking_id' => $bookingId,
                    'hotel_booking_id' => $hotelBookingId,
                    'preBookingData' => $encryptedData,
                ]
            );

        } catch (\Exception $e) {
            Toast::title($e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(10);
            return redirect()->back()->with('error', $e->getMessage());
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
            Toast::title('Invalid or expired booking data. Please try again.')
            ->danger()
            ->rightBottom()
            ->autoDismiss(10);
            return redirect()->back()->with('error', 'Invalid or expired booking data. Please try again.');
        }

        if (!$bookingId || !$hotelBookingId || !$preBookingData) {
            return redirect()->back()->with('error', 'Missing booking preview data.');
        }

        $booking = Booking::find($bookingId);
        $hotelBooking = HotelBooking::find($hotelBookingId);

        return view('hotelBooking.partials.booking_form', compact('message', 'booking', 'hotelBooking', 'preBookingData'));
    }

    public function store(Request $request)
    {
        try {
            $booking = Booking::find($request->booking_id);
            $hotelBooking = HotelBooking::find($request->hotel_booking_id);
            $hotelRoomDetails = HotelRoomDetail::where('booking_id', $hotelBooking->id)->get();
            $salutations = [];
            $firstNames = [];
            $lastNames = [];
            $childAgesFlat = [];
            $totalCapacityPerRoom = [];

            $adultCapacities = [];
            $childCapacities = [];
            $childAges = [];

            $guestIndex = 1;

            foreach ($hotelRoomDetails->groupBy('room_no') as $roomNo => $guests) {
                $adultCount = 0;
                $childCount = 0;
                $childAgeList = [];

                foreach ($guests as $guest) {
                    $salutations[$guestIndex] = $guest->passenger_title;
                    $firstNames[$guestIndex] = $guest->passenger_first_name;
                    $lastNames[$guestIndex] = $guest->passenger_last_name;

                    if ($guest->passenger_title === 'Child') {
                        $childCount++;
                        $childAge = (string) $guest->child_ages;
                        $childAgeList[] = $childAge;
                        $childAgesFlat[$guestIndex] = $childAge;
                    } else {
                        $adultCount++;
                    }

                    $guestIndex++;
                }
                $adultCapacities[$roomNo] = (string) $adultCount;
                $childCapacities[$roomNo] = (string) $childCount;
                $childAges[$roomNo] = $childAgeList; // Reset index to 0
                $totalCapacityPerRoom[$roomNo] = $adultCount + $childCount;
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

                // Guest info per index
                'title' => $salutations,
                'first_name' => $firstNames,
                'last_name' => $lastNames,
                'child_age' => $childAgesFlat,

                // Required for loop in XML generation
                'totalCapacityPerRoom' => $totalCapacityPerRoom,
            ];
            $hotelBookingApi = $this->rezliveHotelService->hotelBooking($dataArray);
            if ((string) $hotelBookingApi->BookingDetails->BookingStatus === 'Fail' || (string) $hotelBookingApi->BookingDetails->BookingStatus === 'Rejected') {
                Toast::title('Booking Fail')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(10);
                return redirect()->back()->with('error', 'Booking Fail');
            }
            $user = auth()->user();
            // If the payment method is offline, handle credit deduction and booking status update
            if ($request->input('pay_offline') === '1' || $request->input('pay_online') === '1') {
                // Update payment type and booking status
                $booking->payment_type = 'wallet'; // Set payment type to wallet
                $booking->booking_status = 'vouchered'; // Update booking status to 'vouchered'
                $booking->save();

                $hotelBooking->rezlive_booking_id = (string) $hotelBookingApi->BookingDetails->BookingId;
                $hotelBooking->rezlive_booking_code = (string) $hotelBookingApi->BookingDetails->BookingCode;
                $hotelBooking->general_remarks = $request->general_remarks;
                $hotelBooking->approved = '1';
                $hotelBooking->save();
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

            Toast::title('Booking Successful')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Booking Successful');

        } catch (\Exception $e) {
            Toast::title($e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(10);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function viewDetails($id)
    {
        $booking = HotelBooking::where('booking_id', $id)->firstOrFail();
        $roomDetails = HotelRoomDetail::where('booking_id', $booking->id)->get();

        $roomDetails->transform(function ($item) {
            $nationality = Country::where('id', $item->nationality_id)->value('name');
            $item->nationality = $nationality;
            return $item;
        });
        $countries = Country::pluck('name', 'id');

        $roomDetails = SpladeTable::for(
            HotelRoomDetail::where('booking_id', $booking->id)
                ->with('nationality')
        )->paginate(10);

        $roomDetails = SpladeTable::for(HotelRoomDetail::where('booking_id', $booking->id)->with('nationality'))
            ->column('room_no', 'Room No')
            ->column('passenger_full_name', 'Passenger Name')
            ->column('passenger_email_address', 'Passenger Email')
            ->column('passenger_contact_number', 'Passenger Contact No.')
            // ->column('number_of_adults', 'No. of Adult(s)')
            // ->column('number_of_children', 'No. of Children')
            // ->column('child_ages', 'Child Age(s)')
            ->column(key: 'extra_bed_for_child', label: 'Child Bed', as: function ($column, $model) {
                return $model->extra_bed_for_child === 0 ? 'NO' : 'YES';
            })
            ->column('nationality.name', 'Nationality')
            ->column('action', 'Action')
            ->paginate(10);

        $currency = $booking->currency;

        $bookingStatus = Booking::where('id', $id)->first();

        $user = User::where('id', $booking->user_id)->first();
        $createdBy = (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Admin';

        $location = Location::where('id', $booking->location_id)->value('name');

        $nationality = Country::where('id', $booking->nationality_id)->value('name');
        // $hotels = GentingHotel::pluck('hotel_name', 'id');
        // $bookedHotel = optional($booking->genRate->gentingHotel)->id ?? null;
        $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $bookingStatus->service_date);
        // Get the current date and time
        $currentDate = Carbon::now();
        // Calculate the difference in days
        $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
        $user = auth()->user();
        $adminCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();
        $can_edit = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('update booking'));
        $can_delete = !($user->type == 'staff' && !in_array($user->agent_code, $adminCodes) && Gate::denies('delete booking'));
        $fullRefund = route('fullRefund', ['service_id' => $bookingStatus->booking_type_id, 'service_type' => $bookingStatus->booking_type]);
        // Return the view with booking details
        return view('hotelBooking.details', compact(
            'booking',
            'nationality',
            'countries',
            'location',
            'currency',
            'createdBy',
            'bookingStatus',
            'remainingDays',
            'can_edit',
            'can_delete',
            'roomDetails',
            'fullRefund'
        ));
    }

    public function roomEdit(Request $request, $booking_id, $room_no)
    {
        try {
            // Fetch the booking record
            $passengerDetails = HotelRoomPassengerDetail::where('id', $booking_id)->where('room_detail_id', $room_no)->first();
            // Check if the booking exists
            if (!$passengerDetails) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found.'
                ], 404);
            }

            // Validate the incoming data
            $request->validate([
                'passenger_full_name' => 'required|string|max:255',
                'passenger_email_address' => 'nullable|email|max:255',
                'passenger_contact_number' => 'nullable',
            ]);

            // Update the booking with validated data
            $passengerDetails->update($request->only([
                'passenger_full_name',
                'passenger_email_address',
                'passenger_contact_number',
                'nationality_id',
                'phone_code',
            ]));
            $is_updated = 1;
            $this->gentingService->sendVoucherEmail($request, $passengerDetails->roomDetail->booking, $is_updated);
            Toast::title('Booking details updated successfully.')
                ->success()
                ->rightBottom()
                ->autoDismiss(5);
            return redirect()->back()->with('success', 'Booking details updated successfully.');
        } catch (\Exception $e) {
            Toast::title('Something went wrong! Please try again later.')
                ->message($e->getMessage()) // Display the actual error message
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);
            return back()->withErrors(['error' => 'Something went wrong! Please try again later.']);
        }
    }

}
