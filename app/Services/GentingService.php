<?php

namespace App\Services;

use App\Jobs\CreateBookingPDFJob;
use App\Jobs\GentingBookingPDFJob;
use App\Jobs\SendEmailJob;
use App\Jobs\TourBookingPDFJob;
use App\Mail\BookingApprovalPending;
use App\Mail\BookingApprovalPendingAdmin;
use App\Mail\BookingMail;
use App\Mail\BookingMailToAdmin;
use App\Mail\Genting\GentingBookingInvoiceMail;
use App\Mail\Genting\GentingBookingRequest;
use App\Mail\Genting\GentingBookingVoucherMail;
use App\Mail\Genting\GentingVoucherToAdminMail;
use App\Mail\Tour\TourBookingInvoiceMail;
use App\Mail\Tour\TourBookingVoucherMail;
use App\Mail\Tour\TourVoucherToAdminMail;
use App\Mail\Tour\TransferBookingInvoiceMail;
use App\Models\DiscountVoucher;
use App\Models\GentingAddBreakFast;
use App\Models\GentingPackage;
use App\Models\GentingRoomPassengerDetail;
use App\Models\GentingSurcharge;
use App\Models\VoucherRedemption;
use Auth;
use GuzzleHttp\Client;
use App\Models\CurrencyRate;
use App\Models\CancellationPolicies;
use App\Services\CurrencyService;
use App\Services\BookingService;
use App\Models\AgentPricingAdjustment;
use App\Models\Booking;
use App\Models\TourBooking;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\GentingBooking;
use App\Models\GentingHotel;
use App\Models\GentingRate;
use App\Models\GentingRoomDetail;
use App\Models\Tour;
use App\Models\Location;
use App\Models\TourDestination;
use App\Models\TourRate;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Toast;

class GentingService
{

    protected $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    public function extractRequestParameters(Request $request)
    {
        // echo "<pre>";print_r($request->all());die();
        $allChildAges = $request->input('child_ages', []); // Get all child ages as a flat array
        $childAgeIndex = 0; // Track index for distributing child ages
        $roomDetails = [];

        for ($i = 1; $i <= ($request->input('rooms') ?? 1); $i++) {
            $adultCapacity = $request->input("adult_capacity_room_$i", 1);
            $childCapacity = $request->input("child_capacity_room_$i", 0);
            $roomChildAges = [];

            // Distribute child ages based on child capacity for each room
            for ($j = 0; $j < $childCapacity; $j++) {
                if (isset($allChildAges[$childAgeIndex])) {
                    $roomChildAges[] = $allChildAges[$childAgeIndex];
                    $childAgeIndex++; // Move to the next age in the flat array
                }
            }

            $roomDetails[] = [
                'room_number' => $i,
                'adult_capacity' => $adultCapacity,
                'child_capacity' => $childCapacity,
                'child_ages' => $roomChildAges, // Assign proper child ages per room
            ];
        }

        return [
            'check_in_out' => $request->input('check_in_out'),
            'rooms' => $request->input('rooms', 1),
            'location' => $request->input('location'),
            'room_details' => $roomDetails, // Properly structured room details
            'price' => $request->input('price'),
            'hotel_name' => $request->input('hotel_name'),
            'package' => $request->input('package'),
            'currency' => $request->input('currency'),
        ];
    }

    public function extractRequestParametersAdmin(Request $request)
    {
        $roomDetails = $request->input('room_details') ?? [];

        // Ensure every room has 'child_ages' key
        $roomDetails = array_map(function ($room) {
            if (!isset($room['child_ages']) || !is_array($room['child_ages'])) {
                $room['child_ages'] = [];
            }
            return $room;
        }, $roomDetails);

        return [
            'check_in_out' => $request->input('check_in_out'),
            'rooms' => $request->input('rooms', 1),
            'location' => $request->input('location'),
            'room_details' => $roomDetails,
            'price' => $request->input('price'),
            'hotel_name' => $request->input('hotel_name'),
            'package' => $request->input('package'),
            'currency' => $request->input('currency'),
        ];
    }

    public function buildQuery(array $parameters)
    {
        $query = GentingHotel::query();

        // Select columns from genting_hotels
        $query->select(
            'genting_hotels.id',
            'genting_hotels.hotel_name as hotel_name',
            'genting_hotels.location_id',
            'genting_hotels.descriptions',
            'genting_hotels.facilities',
            'genting_hotels.others',
            'genting_hotels.images',
            'genting_hotels.closing_day',
            'genting_rates.id as rate_id',
            'genting_rates.room_type',
            'genting_rates.room_capacity',
            'genting_rates.effective_date',
            'genting_rates.expiry_date',
            'genting_rates.genting_hotel_id',
            'genting_rates.price',
            'genting_rates.currency',
            'genting_rates.bed_count',
            'genting_rates.images as rate_images'
        );

        // Join genting_rates with a LEFT JOIN
        $query->leftJoin('genting_rates', 'genting_hotels.id', '=', 'genting_rates.genting_hotel_id');

        $query->leftJoin('genting_surcharges', 'genting_hotels.id', '=', 'genting_surcharges.genting_hotel_id')
            ->addSelect('genting_surcharges.surcharges');

        // Filter by location
        if (!empty($parameters['location'])) {
            $location = Location::where('name', $parameters['location'])->first();
            if ($location) {
                $query->where('genting_hotels.location_id', $location->id);
            }
        }

        // Filter by room capacities
        if (!empty($parameters['room_details'])) {
            $query->where(function ($q) use ($parameters) {
                foreach ($parameters['room_details'] as $room) {
                    $totalCapacity = ($room['adult_capacity'] ?? 0) + ($room['child_capacity'] ?? 0);
                    $q->where('genting_rates.room_capacity', '>=', $totalCapacity);
                }
            });
        }

        // ORDERING
        $query->orderBy('genting_rates.room_capacity', 'asc');

        // Fetch the results
        $results = $query->get();

        // Extract check-in and check-out dates
        $decodedDateRange = urldecode($parameters['check_in_out']);
        $dates = explode(' to ', $decodedDateRange);
        $checkIn = Carbon::parse(trim($dates[0]));
        $checkOut = Carbon::parse(trim($dates[1] ?? ''));
        $numNights = $checkIn->diffInDays($checkOut); // Number of nights
        $numRooms = count($parameters['room_details']); // Number of selected rooms

        $filteredResults = [];

        foreach ($results as $result) {
            $isPackage = $result->is_package ?? false; // Check if result has a package price
            $totalPrice = 0;

            // **BASE PRICE CALCULATION**
            if ($isPackage) {
                // If it's a package, price remains the same regardless of nights
                $totalPrice = $result->package_price * $numRooms;
            } else {
                // If it's nightly pricing, apply per night cost
                $totalPrice = $result->price * max(1, $numNights);
            }

            // **APPLY SURCHARGES**
            if (!empty($result->surcharges)) {
                $surchargeAmount = $this->calculateGentingSurcharges($result->surcharges, $checkIn, $checkOut);
                $totalPrice += $surchargeAmount;
            }

            // Store the updated price
            $result->total_price = $totalPrice * $numRooms;
            $filteredResults[] = $result;
        }



        // FILTER RESULTS BASED ON EFFECTIVE & EXPIRY DATE
        $filteredResults = collect($filteredResults)->filter(function ($result) use ($checkIn, $checkOut) {
            $effectiveDate = Carbon::parse($result->effective_date);
            $expiryDate = Carbon::parse($result->expiry_date);
            return $checkIn->greaterThanOrEqualTo($effectiveDate) && $checkOut->lessThanOrEqualTo($expiryDate);
        })->sortBy('total_price')->values();

        return $filteredResults;
    }

    public function applyFiltersAndPaginate($results, array $parameters)
    {
        // Filter by price range
        if (!empty($parameters['price'])) {
            $priceRange = $this->extractPriceRange($parameters['price']);
            if ($priceRange) {
                $currentCurrency = $parameters['currency'] ?? 'MYR'; // Default to MYR if no currency is provided
                $convertedPriceRange = $this->convertPriceRangeToMYR($priceRange, $currentCurrency);
                if ($convertedPriceRange) {
                    $results = $results->whereBetween('total_price', $convertedPriceRange);
                }
            }
        }

        // Filter by hours
        if (!empty($parameters['hour'])) {
            $results = $results->where('hours', $parameters['hour']);
        }

        // Filter by package
        if (!empty($parameters['package'])) {
            $results = $results->whereIn('package', $parameters['package']);
        }

        // Filter by location_id
        if (!empty($parameters['location_id'])) {
            $results = $results->where('location_id', $parameters['location_id']);
        }

        // Filter by hotel name
        if (!empty($parameters['hotel_name'])) {
            $hotelNames = is_array($parameters['hotel_name'])
                ? $parameters['hotel_name']
                : explode(',', $parameters['hotel_name']);
            $results = $results->whereIn('hotel_name', $hotelNames);
        }

        // Sort the collection by price (ascending order)
        $results = $results->sortBy('price'); // Using sortBy for collections

        // Group by hotel name
        $groupedResults = $results->groupBy('hotel_name');

        // Get the current page from the request
        $page = request()->input('page', 1);

        // Define how many groups per page
        $perPage = 10;

        // Slice the grouped results for the current page
        $pagedGroupedResults = $groupedResults->slice(($page - 1) * $perPage, $perPage);

        // Create a LengthAwarePaginator instance
        $paginatedGroupedResults = new LengthAwarePaginator(
            $pagedGroupedResults, // Items for the current page
            $groupedResults->count(), // Total number of groups
            $perPage, // Groups per page
            $page, // Current page
            ['path' => request()->url(), 'query' => $parameters] // Pagination links
        );

        return $paginatedGroupedResults;
    }

    private function convertPriceRangeToMYR(array $priceRange, string $currentCurrency)
    {
        $convertedRange = [
            $this->applyCurrencyConversion($priceRange[0], $currentCurrency, 'MYR'),
            $this->applyCurrencyConversion($priceRange[1], $currentCurrency, 'MYR'),
        ];

        return $convertedRange;
    }


    public function adjustGenting($rates, array $parameters, $type = null)
    {

        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        if ($type == 'gentingView') {

            foreach ($rates as $rate) {
                // Apply currency conversion

                $rate->total_price = $this->applyCurrencyConversion($rate->total_price, $rate->currency, $parameters['currency']);
                // $rate->total_ticketprice = $this->applyCurrencyConversion($rate->total_ticketprice, $rate->currency, $parameters['currency']);
                $rate->adult = $this->applyCurrencyConversion($rate->adult, $rate->currency, $parameters['currency']);
                $rate->child = $this->applyCurrencyConversion($rate->child, $rate->currency, $parameters['currency']);
                // Loop through all adjustment rates
                foreach ($adjustmentRates as $adjustmentRate) {
                    if ($adjustmentRate->transaction_type === 'genting_hotel') {
                        $rate->total_price = round($this->applyAdjustment($rate->total_price, $adjustmentRate), 2);
                    }
                }
            }
        } else {
            foreach ($rates as $gentingName => $group) {

                foreach ($group as $rate) {
                    // Apply currency conversion

                    $rate->total_price = $this->applyCurrencyConversion($rate->total_price, $rate->currency, $parameters['currency']);
                    // $rate->total_ticketprice = $this->applyCurrencyConversion($rate->total_ticketprice, $rate->currency, $parameters['currency']);
                    $rate->adult = $this->applyCurrencyConversion($rate->adult, $rate->currency, $parameters['currency']);
                    $rate->child = $this->applyCurrencyConversion($rate->child, $rate->currency, $parameters['currency']);
                    // Loop through all adjustment rates
                    foreach ($adjustmentRates as $adjustmentRate) {
                        if ($adjustmentRate->transaction_type === 'genting_hotel') {
                            $rate->total_price = round($this->applyAdjustment($rate->total_price, $adjustmentRate), 2);
                        }
                    }
                }
            }
        }

        return $rates;
    }

    public function applyCurrencyConversion($rate, $currentCurrency, $targetCurrency)
    {
        if ($targetCurrency) {
            $usdRate = CurrencyService::convertCurrencyToUsd($currentCurrency, $rate);
            return round(CurrencyService::convertCurrencyFromUsd($targetCurrency, $usdRate), 2);
        }
        return $rate;
    }

    public function applyAdjustment($rate, $adjustmentRate)
    {
        // Check if $adjustmentRate is a valid object with the expected properties
        if ($adjustmentRate && isset($adjustmentRate->active, $adjustmentRate->percentage, $adjustmentRate->percentage_type)) {
            if ($adjustmentRate->active !== 0) {
                $percentage = $adjustmentRate->percentage;

                // Apply the adjustment based on percentage_type
                return $adjustmentRate->percentage_type === 'surcharge'
                    ? $rate + ($rate * ($percentage / 100))
                    : $rate - ($rate * ($percentage / 100));
            }
        }

        return $rate;
    }


    public function extractPriceRange($priceRanges)
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

    public function getFilters(array $parameters, $adjustedRates)
    {
        if (!empty($parameters['location']['name'])) {
            // Fetch the location_id based on the provided search location
            $locationId = Location::where('name', $parameters['location']['name'])->value('id');
            if ($locationId) {
                // Fetch the destination names based on the location_id
                $destinations = GentingHotel::where('location_id', $locationId)
                    ->pluck('hotel_name');
            } else {
                // If the location doesn't exist, set an empty collection
                $destinations = collect([]);
            }
        } else {
            // If searchLocation is empty, return all or handle as needed
            $destinations = collect([]);
        }

        // Fetch distinct packages directly from Genting Packages
        $vehicleTypes = GentingRate::join('genting_packages', 'genting_rates.genting_package_id', '=', 'genting_packages.id')
            ->distinct()
            ->pluck('genting_packages.package');

        // Extract total_price from $adjustedRates
        $prices = $adjustedRates->flatMap(function ($group) {
            return $group->pluck('price');
        })->map(fn($price) => (int) $price)->unique()->values();

        return [
            'destinations' => $destinations,
            'vehicleTypes' => $vehicleTypes,
            'prices' => $prices,
        ];
    }


    public function prepareBookingData(Request $request, $gentingBooking, $is_updated = 0)
    {
        $roomDetails = GentingRoomDetail::with('passengers')->where('booking_id', $gentingBooking->id)->get();
        $extra_bed_for_child = '';
        foreach ($roomDetails as $room) {
            if ($room->extra_bed_for_child == 1) {
                $extra_bed_for_child = 'Yes';
                break;
            }
        }
        $currency = ($request->input('currency') ?? $gentingBooking->currency);

        // Add nationality and prepare traveller data
        $roomDetails->transform(function ($room) {
            $room->nationality = optional(Country::find($room->nationality_id))->name ?? 'None';

            // Sort passengers by ID for consistent indexing
            $passengers = $room->passengers->sortBy('id')->values();

            // Decode child ages JSON
            $childAges = json_decode($room->child_ages ?? '[]', true);
            $childIndex = 0;

            foreach ($passengers as $passenger) {
                if ($passenger->passenger_title === 'Child') {
                    $passenger->traveller_type = isset($childAges[$childIndex])
                        ? 'Child (' . $childAges[$childIndex] . 'yo)'
                        : 'Child';
                    $childIndex++;
                } elseif (in_array($passenger->passenger_title, ['Mr.', 'Ms.', 'Mrs.'])) {
                    $passenger->traveller_type = 'Adult';
                } else {
                    $passenger->traveller_type = $passenger->passenger_title ?? 'N/A';
                }

                $passenger->nationality = optional(Country::find($passenger->nationality_id))->name ?? 'None';
            }

            $room->passengers = $passengers;
            return $room;
        });

        // Group by room number
        $groupedByRoom = $roomDetails->groupBy('room_no');
        // dd($groupedByRoom);
        $user = User::where('id', $gentingBooking->user_id)->first() ?? auth()->user();

        // Retrieve admin and agent logos from the Company table
        $adminLogo = public_path('/img/logo.png');

        // First get the agent_code of the current user
        $agentCode = $user->agent_code;

        // Then find the actual agent user who owns this agent_code
        $agent = User::where('type', 'agent')->where('agent_code', $agentCode)->first();

        $agentLogo = null;

        $timezone_abbreviation = 'UTC'; // fallback
        $timezones = json_decode($gentingBooking->location->country->timezones);
        if (is_array($timezones) && isset($timezones[0]->abbreviation)) {
            $timezone_abbreviation = $timezones[0]->abbreviation;
        }
        if ($agent) {
            // Now get the logo from the company table using the agent's ID
            $agentLogo = Company::where('user_id', $agent->id)->value('logo');
        }

        $agentLogoUrl = asset(str_replace('/public/', '', $agentLogo));
        $agentLogo = $agentLogo ? public_path(str_replace('/public/', '', $agentLogo)) : $adminLogo;
        if (file_exists($agentLogo) && is_readable($agentLogo)) {
            $imageData = base64_encode(file_get_contents($agentLogo));
        } else {
            // If the agent logo is not found, use the admin logo
            $imageData = base64_encode(file_get_contents($adminLogo));
        }
        // $imageData = base64_encode(file_get_contents($agentLogo));
        $agentLogo = 'data:image/png;base64,' . $imageData;

        $company = Company::where('user_id', $agent->id ?? $user->id)->first();
        $agentCompanyAddress = $company->address ?? 'Unit B-04-16, Block B, Perdana Selatan, Taman Serdang Perdana Seksyan 1,';
        $agentCompanyName = $company->agent_name ?? 'GR TRAVEL & TOURS SDN BHD';
        $agentCompanyWebsite = $company->agent_website ?? 'booking.grtravels.net';
        $companyCity = $company->city_id ?? '76497';
        $agentCompanyCity = City::where('id', $companyCity)->value('name');
        $agentCompanyZip = $company->zip ?? '43300';
        $companyNumber = $company->agent_number ?? '14 331 9802';
        $companyPhoneCode = $company->phone_code_company ?? '+60';
        $agentCompanyNumber = $companyPhoneCode . ' ' . $companyNumber;

        $basePrice = $currency . ' ' . $gentingBooking->total_cost;
        $rateId = $gentingBooking->genting_rate_id;
        $gentingRate = GentingRate::where('id', $rateId)->first();
        $remarks = $gentingRate->remarks ?? '';

        $phone = $agent->phone ?? $user->phone;
        $phoneCode = $agent->phone_code ?? $user->phone_code;
        $hirerEmail = $agent->email ?? $user->email;
        $hirerName = ($agent ?? $user)->first_name . ' ' . ($agent ?? $user)->last_name;
        $booking_status = Booking::with(['user.company'])->where('id', $gentingBooking->booking_id)->first();
        $hirerPhone = $phoneCode . $phone;
        // Assign to/from locations based on these values
        $location = Location::where(
            'id',
            $gentingBooking->location_id
        )->value('name');

        $bookingDate = convertToUserTimeZone($gentingBooking->created_at, 'F j, Y H:i T') ?? convertToUserTimeZone($request->input('booking_date'), 'F j, Y H:i T');
        $pickupAddress = $gentingBooking->pickup_address ?? $request->input('pickup_address');
        $dropoffAddress = $gentingBooking->dropoff_address ?? $request->input('dropoff_address');
        $child = $request->input('number_of_children') ?? $gentingBooking->number_of_children;
        $adults = $request->input('number_of_adults') ?? $gentingBooking->number_of_adults;
        $infants = $request->input('number_of_infants') ?? $gentingBooking->number_of_infants;
        // $roomDetails->transform(function ($room, $key) {
        //     $country = Country::find($room->nationality_id);
        //     $room->nationality = $country ? $country->name : 'None';
        //     return $room;
        // });
        // // Group by room number
        // $groupedByRoom = $roomDetails->groupBy('room_no');

        // $weekday = $bookingDate->format('l'); // e.g., 'Saturday'
        // $bookingDateStr = $bookingDate->toDateString(); // e.g., '2025-05-01'

        $checkIn = Carbon::parse(trim($gentingBooking->check_in ?? $request->input('check_in')));
        $checkOut = Carbon::parse(trim($gentingBooking->check_out ?? $request->input('check_out')));
        $numNights = $checkIn->diffInDays($checkOut); // Number of nights
        $numRooms = count($groupedByRoom);
        $breakfast = GentingAddBreakFast::where('hotel_id', $request->hotel_id ?? $gentingBooking->gentingRate->genting_hotel_id)->first();
        $adultPrice = $breakfast->adult ?? 0;
        $childPrice = $breakfast->child ?? 0;

        // $add_breakfast = 0;
        $numberOfChildren = (int) ($request->additional_children ?? $gentingBooking->additional_children);
        $numberOfAdults = (int) ($request->additional_adults ?? $gentingBooking->additional_adults);

        $additional_adult_price = $numberOfAdults * $adultPrice * $numNights;
        $additional_child_price = $numberOfChildren * $childPrice * $numNights;

        // if($numberOfChildren > 0 || $numberOfAdults > 0){
        //     $add_breakfast = $numberOfAdults * $adultPrice + $numberOfChildren * $childPrice;
        // }
        // Calculate base rate per room per night
        // $baseRate = $gentingRate->price * $numRooms * $numNights;
        // $netCurrency = $gentingRate->currency;

        // // Apply surcharge per room per night
        // $totalSurcharge = 0;
        // $gentingSurchargeRecord = GentingSurcharge::where('genting_hotel_id', $gentingRate->genting_hotel_id)->first();

        // if ($gentingSurchargeRecord) {
        //     $surchargePerRoomPerNight = $this->calculateGentingSurcharges($gentingSurchargeRecord->surcharges, $checkIn, $checkOut);
        //     $totalSurcharge = $surchargePerRoomPerNight * $numRooms;
        // }

        // $netRate = $baseRate + $totalSurcharge;
        $voucher = VoucherRedemption::where('booking_id', $booking_status->id)->first();
        $discount = 0;
        $discountedPrice = 0;
        if ($voucher) {
            $discount = $voucher->discount_amount;
            $discountedPrice = str_replace(',', '', $gentingBooking->total_cost) - $discount;
        }
        $entitlements = json_decode($gentingBooking->gentingRate->entitlements, true);
        $entitlements = array_slice($entitlements, 1); // removes the first item
        $paymentMode = $gentingBooking->booking->payment_type;
        return [
            'id' => $gentingBooking->id,
            'booking_id' => $booking_status->booking_unique_id,
            'locationName' => $location,
            'passenger_full_name' => $request->input('passenger_full_name') ?? $gentingBooking->passenger_full_name,
            'passenger_email_address' => $request->input('passenger_email_address') ?? $gentingBooking->passenger_email_address,
            'booking_date' => $bookingDate,
            'tour_date' => '',
            'pick_time' => '',
            'base_price' => $basePrice,
            'hours' => $gentingBooking->hours ?? $request->input('hours'),
            'hotel_name' => $request->input('hotel_name') ?? $gentingBooking->hotel_name,
            'seating_capacity' => $request->input('seating_capacity') ?? $gentingBooking->seating_capacity,
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'agent_voucher_no' => auth()->id(),
            'agentLogo' => $agentLogo,
            'agentCompanyName' => $agentCompanyName,
            'agentCompanyNumber' => $agentCompanyNumber,
            'agentCompanyWebsite' => $agentCompanyWebsite,
            'agentCompanyCity' => $agentCompanyCity,
            'agentCompanyZip' => $agentCompanyZip,
            'hirerName' => $hirerName,
            'hirerPhone' => $hirerPhone,
            'hirerEmail' => $hirerEmail,
            'phone' => $phone,
            'agentCompanyAddress' => $agentCompanyAddress,
            'remarks' => $remarks,
            'booking_status' => $booking_status->booking_status,
            'deadlineDate' => Carbon::parse($booking_status->deadline_date)->format('F j, Y H:i') . ' ' . $timezone_abbreviation,
            'infants' => $infants,
            'child' => $child,
            'adults' => $adults,
            'roomDetails' => $groupedByRoom,
            'room_capacity' => $request->input('room_capacity') ?? $gentingBooking->room_capacity,
            'agentLogoUrl' => $agentLogoUrl,
            'child_ages' => json_decode($request->input('child_ages')) ?? json_decode($gentingBooking->child_ages),
            'is_updated' => $is_updated,
            'created_by_admin' => $gentingBooking->created_by_admin ?? false,
            'updated_at' => convertToUserTimeZone($request->input('updated_at'), 'F j, Y H:i T') ?? convertToUserTimeZone($gentingBooking->updated_at, 'F j, Y H:i T'),
            'type' => $gentingBooking->type ?? $request->input('type'),
            'package' => $gentingBooking->package ?? $request->input('package'),
            'check_out' => $gentingBooking->check_out ?? $request->input('check_out'),
            'check_in' => $gentingBooking->check_in ?? $request->input('check_in'),
            'room_type' => $gentingBooking->room_type ?? $request->input('room_type'),
            'number_of_rooms' => $gentingBooking->number_of_rooms ?? $request->input('number_of_rooms'),
            'entitlements' => $entitlements,
            'extra_bed_for_child' => $extra_bed_for_child,
            'reservation_id' => $gentingBooking->reservation_id ?? null,
            'confirmation_id' => $gentingBooking->confirmation_id ?? null,
            'additional_adults' => $gentingBooking->additional_adults,
            'additional_children' => $gentingBooking->additional_children,
            'additional_adult_price' => $additional_adult_price,
            'additional_child_price' => $additional_child_price,
            'netRate' => $booking_status->net_rate,
            'netCurrency' => $booking_status->net_rate_currency,
            'bookingCreatedBy' => $booking_status->user->agent_name,
            'paymentMode' => $paymentMode,
            'voucher_code' => $request->voucher_code,
            'voucher' => $voucher ? $voucher->voucher : null,
            'discount' => $discount,
            'currency' => $currency,
            'discountedPrice' => $discountedPrice,
        ];
    }

    public function createBookingPDF($bookingData, $email, Request $request, $gentingBooking)
    {
        $user = User::where('id', $gentingBooking->user_id)->first();
        GentingBookingPDFJob::dispatch($bookingData, $email, $gentingBooking, $user);

        return true;
        $passengerName = $user->first_name . ' ' . $user->last_name;

        $directoryPath = public_path("bookings");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015
        $id = $gentingBooking->id;
        $pdfFilePathVoucher = "{$directoryPath}/genting_booking_voucher_{$timestamp}_{$id}.pdf";
        $pdfFilePathInvoice = "{$directoryPath}/genting_booking_invoice_{$timestamp}_{$id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/genting_booking_admin_voucher_{$timestamp}_{$id}.pdf";

        // Load the view and save the PDF
        $pdf = Pdf::loadView('email.genting.genting_booking_voucher', $bookingData);
        $pdf->save($pdfFilePathVoucher);
        // booking_voucher
        $pdf = Pdf::loadView('email.genting.genting_booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);
        //voucher to admin
        $pdf = Pdf::loadView('email.genting.genting_booking_admin_voucher', $bookingData);
        $pdf->save($pdfFilePathAdminVoucher);
        $cc = [config('mail.notify_genting'), config('mail.notify_genting')];

        $mailInstance = new GentingBookingVoucherMail($bookingData, $pdfFilePathInvoice, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to($email)->send(new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName));
        // $mailInstance = new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName);
        // SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to(['tours@grtravel.net', 'info@grtravel.net'])->send(new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName));
        // $email = ['tours@grtravel.net', 'info@grtravel.net'];
        // $mailInstance = new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName);
        // SendEmailJob::dispatch($email, $mailInstance);
    }

    public function sendVoucherEmail($request, $gentingBooking, $is_updated = 0)
    {
        $bookingData = $this->prepareBookingData($request, $gentingBooking, $is_updated);
        $user = User::where('id', $gentingBooking->user_id)->first();
        $passengerName = $user->first_name . ' ' . $user->last_name;
        $directoryPath = public_path("bookings/genting");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015
        if ($gentingBooking->booking->booking_status === 'vouchered') {
            $pdfFilePathInvoice = "{$directoryPath}/genting_invoice_paid_{$timestamp}_{$gentingBooking->booking_id}.pdf";
        } else {
            $pdfFilePathInvoice = "{$directoryPath}/genting_booking_invoice_{$timestamp}_{$gentingBooking->booking_id}.pdf";
        }
        $pdfFilePathVoucher = "{$directoryPath}/genting_booking_voucher_{$timestamp}_{$gentingBooking->booking_id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/genting_booking_admin_voucher_{$timestamp}_{$gentingBooking->booking_id}.pdf";
        // booking_voucher
        $pdf = Pdf::loadView('email.genting.genting_booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);

        $pdfVoucher = Pdf::loadView('email.genting.genting_booking_voucher', $bookingData);
        $pdfVoucher->save($pdfFilePathVoucher);

        $pdfAdmin = Pdf::loadView('email.genting.genting_booking_admin_voucher', $bookingData);
        $pdfAdmin->save($pdfFilePathAdminVoucher);

        $email = $user->email;

        // $isCreatedByAdmin = $tourBooking->created_by_admin; // to track creation by admin

        // Mail::to($email)->send(new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName));
        $mailInstance = new GentingBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to($email)->send(new BookingMail($bookingData, $pdfFilePathVoucher, $passengerName));
        $mailInstance = new GentingBookingVoucherMail($bookingData, $pdfFilePathVoucher, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);
        if (auth()->user()->type !== 'admin') {
            // Mail::to(['tours@grtravel.net', 'info@grtravel.net'])->send(new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName));
            $emailAdmin1 = config('mail.notify_genting');
            $emailAdmin2 = config('mail.notify_info');
            $emailAdmin3 = config('mail.notify_account');
            $mailInstance = new GentingVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin1, $mailInstance);
            $mailInstance = new GentingVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin2, $mailInstance);
            $mailInstance = new GentingVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin3, $mailInstance);
        }
    }

    public function sendInvoiceAndVoucher($booking)
    {
        // Prepare booking data for the emails
        $bookingData = $this->prepareBookingData(request(), $booking, $booking->dropoff_name);
        // Generate and send the invoice PDF
        $this->createBookingPDF($bookingData, $booking->email, request(), $booking);
    }

    public function printVoucher($id)
    {
        // Retrieve the booking by its ID
        $gentingBooking = GentingBooking::find($id);
        $booking = Booking::where('id', $gentingBooking->booking_id)->first();
        if (!$gentingBooking) {
            // Return a JSON response with an error message if booking is not found
            return response()->json(['error' => 'Voucher not found.'], Response::HTTP_NOT_FOUND);
        }
        // Get the created_at date and format it as Ymd (e.g., 20241030)
        $createdDate = $gentingBooking->updated_at->format('Ymd');

        if (Auth::user()->type === 'admin') {
            $fileName = 'genting_booking_admin_voucher_' . $createdDate . '_' . $booking->id . '.pdf';
        } else {
            $fileName = 'genting_booking_voucher_' . $createdDate . '_' . $booking->id . '.pdf';
        }
        $filePath = public_path('bookings/genting/' . $fileName);

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return response()->json(['error' => 'Voucher file not found.'], Response::HTTP_NOT_FOUND);
    }

    public function printInvoice($id)
    {
        // Retrieve the booking by its ID
        $gentingBooking = GentingBooking::find($id);
        $booking = Booking::where('id', $gentingBooking->booking_id)->first();
        if (!$gentingBooking) {
            // Return a JSON response with an error message if booking is not found
            return response()->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        // Get the created_at date and format it as Ymd (e.g., 20241030)
        $createdDate = $gentingBooking->updated_at->format('Ymd');

        if ($booking->booking_status === 'vouchered') {
            $fileName = 'genting_invoice_paid_' . $createdDate . '_' . $booking->id . '.pdf';
            $filePath = public_path('bookings/genting/' . $fileName);
        } else {
            $fileName = 'genting_booking_invoice_' . $createdDate . '_' . $booking->id . '.pdf';
            $filePath = public_path('bookings/genting/' . $fileName);
        }

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return response()->json(['error' => 'Invoice file not found in the directory.'], Response::HTTP_NOT_FOUND);
    }

    public function updatePassenger(Request $request, $booking)
    {
        // Validate the incoming data
        $request->validate([
            'passenger_full_name' => 'required|string|max:255',
            'passenger_email_address' => 'nullable|email|max:255',
            'passenger_contact_number' => 'nullable',
        ]);

        // Update the booking with validated data
        $booking->update($request->only([
            'passenger_full_name',
            'passenger_email_address',
            'passenger_contact_number',
            'nationality_id',
            'special_request',
            'phone_code',
        ]));
        $gentingBooking = GentingBooking::with('location.country')->where('id', $booking->roomDetail->booking_id)->first();
        $is_updated = 1;
        $this->sendVoucherEmail($request, $gentingBooking, $is_updated);

        return response()->json(['message' => 'Booking updated successfully!', 'booking' => $booking]);
    }

    public function storeBooking(Request $request)
    {
        $data = $request->all();
        $request = new Request($data);
        $payment_type = '';
        $rooms = $request->input('room_details') ?? $request->input('roomDetails');
        if (!is_array($rooms)) {
            $rooms = json_decode($rooms, true);
        }
        if (empty($rooms)) {
            $rooms = [
                [
                    "room_number" => 1,
                    "adult_capacity" => "1",
                    "child_capacity" => "0",
                    "child_ages" => []
                ]
            ];
        }

        $rawPassengers = $request->input('passengers', []);

        $passengerData = collect($rawPassengers)->map(function ($roomPassengersRaw) {
            // Extract the bed value if it exists
            $bed = is_array($roomPassengersRaw) && isset($roomPassengersRaw['bed']) ? $roomPassengersRaw['bed'] : 0;

            // Remove 'bed' from the array to just keep the passengers
            $roomPassengers = collect($roomPassengersRaw)->filter(fn($item) => is_array($item))->values();

            return [
                'bed' => $bed,
                'passengers' => $roomPassengers
                    ->filter(function ($passenger) {
                        return !empty($passenger['full_name'])
                            || !empty($passenger['phone_code'])
                            || !empty($passenger['nationality_id']);
                    })
                    ->values()
                    ->toArray()
            ];
        })->filter(fn($room) => !empty($room['passengers']))->values()->toArray();


        // Attach passengers to their respective rooms
        foreach ($rooms as $index => &$room) {
            $room['passengers'] = $passengerData[$index]['passengers'] ?? [];
            $room['bed'] = $passengerData[$index]['bed'] ?? 0;
        }
        // Fetch the hotel with a 1-night package first
        $getData = GentingRate::where('id', $request->genting_rate_id)
            ->whereHas('gentingPackage', function ($query) {
                $query->where('nights', '<', 2); // Only allow packages with less than 2 nights
            })
            ->where(function ($query) use ($rooms) {
                foreach ($rooms as $room) {
                    $totalCapacity = (int) $room['adult_capacity'] + (int) $room['child_capacity'];
                    $query->where('room_capacity', '>=', $totalCapacity);
                }
            })
            ->first();

        // If no 1-night package is found, try fetching a 2-night package instead
        if (!$getData) {
            $getData = GentingRate::where('id', $request->genting_rate_id)
                ->whereHas('gentingPackage', function ($query) {
                    $query->where('nights', '>', 1); // Fetch only 2-night packages
                })
                ->where(function ($query) use ($rooms) {
                    foreach ($rooms as $room) {
                        $totalCapacity = (int) $room['adult_capacity'] + (int) $room['child_capacity'];
                        $query->where('room_capacity', '>=', $totalCapacity);
                    }
                })
                ->first();
        }

        if ($getData) {  // Check if data exists
            $checkIn = Carbon::parse($request->check_in);
            $checkOut = Carbon::parse($request->check_out);

            // Calculate number of nights
            $numNights = $checkIn->diffInDays($checkOut);
            $totalRooms = count($rooms);
            $totalSurcharge = 0;

            // Fetch surcharge details from genting_surcharges table
            $surchargeData = GentingSurcharge::where('genting_hotel_id', $getData->genting_hotel_id)
                ->value('surcharges');

            if ($surchargeData) {
                $surcharges = json_decode($surchargeData, true) ?? [];

                // Extract weekend days dynamically from the JSON
                $weekendDays = [];
                foreach ($surcharges as $surcharge) {
                    if ($surcharge['surcharge_type'] === 'weekend') {
                        $weekendDays[] = ucfirst(strtolower($surcharge['surcharge_details']['weekend']));
                    }
                }

                $dateRangeApplied = false; // Ensure date range surcharge is applied only once
                $appliedWeekendSurcharge = false;
                // Loop through each night of the stay (excluding checkout date)
                $currentDate = clone $checkIn;
                while ($currentDate->lt($checkOut)) {
                    foreach ($surcharges as $surcharge) {
                        if ($surcharge['surcharge_type'] === 'weekend' && in_array($currentDate->format('l'), $weekendDays) && !$appliedWeekendSurcharge) {
                            $totalSurcharge += $surcharge['surcharge_details']['amount'];
                            $appliedWeekendSurcharge = true;
                        }
                        if ($surcharge['surcharge_type'] === 'fixed_date' && $currentDate->format('Y-m-d') === $surcharge['surcharge_details']['fixed_date']) {
                            $totalSurcharge += $surcharge['surcharge_details']['amount'];
                        }
                        if ($surcharge['surcharge_type'] === 'date_range') {
                            $startDate = Carbon::parse($surcharge['surcharge_details']['start_date']);
                            $endDate = Carbon::parse($surcharge['surcharge_details']['end_date']);
                            if ($currentDate->between($startDate, $endDate)) {
                                $totalSurcharge += $surcharge['surcharge_details']['amount'];

                            }
                        }
                    }
                    $currentDate->addDay(); // Move to the next day
                }
            }
            if ($getData->gentingPackage->nights > 1) {
                // Net rate before currency conversion
                $originalRate = (($getData->price / 2) * $numNights + $totalSurcharge) * $totalRooms;

                // Apply currency conversion
                $genting_price = $this->applyCurrencyConversion(
                    $originalRate,
                    $getData->currency,
                    $request->currency,
                );
            } else {
                // Net rate before currency conversion
                $originalRate = ($getData->price * $numNights + $totalSurcharge) * $totalRooms;

                // Apply currency conversion
                $genting_price = $this->applyCurrencyConversion(
                    $originalRate,
                    $getData->currency,
                    $request->currency
                );
            }
            $agentCode = auth()->user()->agent_code;

            $agentId = User::where('agent_code', $agentCode)
                ->where('type', 'agent')
                ->value('id');

            $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
            if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {

                $gentingRates = $adjustmentRate->filter(function ($rate) {
                    return $rate->transaction_type === 'genting_hotel';
                });
                foreach ($gentingRates as $gentingRate) {

                    $genting_price = $this->applyAdjustment($genting_price, $gentingRate);
                    $originalRate = $this->applyAdjustment($originalRate, $gentingRate);
                }
            }
        } else {
            return redirect()->back()->with('error', 'Genting Hotel not found');
        }


        $gentingHotels = GentingHotel::where('id', $getData->genting_hotel_id)->first();
        // Check if tour rate data exists
        if (!$gentingHotels) {
            return redirect()->back()->with('error', 'Genting Hotel not found');
        }
        $breakfast = GentingAddBreakFast::where('hotel_id', $gentingHotels->id)->first();
        $convertedAdultPrice = 0;
        $convertedChildPrice = 0;

        if ($breakfast) {
            $convertedAdultPrice = $this->applyCurrencyConversion($breakfast->adult, $breakfast->currency, $request->currency) * $numNights;
            $convertedChildPrice = $this->applyCurrencyConversion($breakfast->child, $breakfast->currency, $request->currency) * $numNights;
        }
        $currencyRate = CurrencyRate::where('target_currency', $request->currency)->first();
        $originalCurrencyRate = CurrencyRate::where('target_currency', $getData->currency)->first();
        // Prepare fleet booking data and save
        $gentingBookingData = $this->gentingData($request);

        $booking_status = 'confirmed';
        $booking_currency = $request->input('currency');
        // Get the Genting hotel breakfast price (assuming hotel_id is passed in request)
        // $breakfast = GentingAddBreakFast::where('hotel_id', $request->hotel_id)->first();
        $adultPrice = $convertedAdultPrice ?? 0;
        $childPrice = $convertedChildPrice ?? 0;

        // Get base total cost
        $totalCost = isset($genting_price)
            ? (float) str_replace(',', '', $genting_price)
            : 0;

        // Get additional breakfast counts
        $additionalAdults = (int) $request->input('additional_adults', 0);
        $additionalChildren = (int) $request->input('additional_children', 0);

        // Calculate extra breakfast cost
        $additionalAdultCost = $additionalAdults * $adultPrice;
        $additionalChildCost = $additionalChildren * $childPrice;
        $additionalBreakfastCost = $additionalAdultCost + $additionalChildCost;

        // Merge additional data into existing array
        $gentingBookingData['additional_adults'] = $additionalAdults;
        $gentingBookingData['additional_children'] = $additionalChildren;
        $gentingBookingData['additional_adult_price'] = $additionalAdultCost;
        $gentingBookingData['additional_child_price'] = $additionalChildCost;

        // Final total cost
        $gentingBookingData['total_cost'] = round($totalCost + $additionalBreakfastCost, 2);
        $gentingPrice = $gentingBookingData['total_cost'];
        // Call the deductCredit method to handle credit deduction for the agent
        $user = auth()->user()->getOwner();

        if ($request->submitButton == "pay_offline") {
            $deductionResult = $this->bookingService->deductCredit($user, $gentingPrice, $booking_currency);
            if ($deductionResult !== true) {
                return response()->json([
                    'success' => false,
                    'error' => 'Insufficient credit limit to create booking.',
                ], 400); // Return HTTP 400 for a bad request
            }
        }

        $action = $request->input('submitButton'); // This will be 'request_booking'
        if ($action === 'request_booking') {
            $booking_status = 'pending_approval';
        }

        $baseRate = $getData->price * $totalRooms * $numNights;
        $netCurrency = $getData->currency;
        $netRate = $baseRate + ($totalSurcharge * $totalRooms);
        $bookingData = [
            'agent_id' => auth()->id(),
            'user_id' => auth()->id(),
            'booking_date' => now()->format('Y-m-d H:i:s'),
            'amount' => str_replace(',', '', $gentingPrice),
            'currency' => $booking_currency,
            'service_date' => $request->input('check_in'),
            'booking_type' => 'genting_hotel',
            'booking_status' => $booking_status,
            'payment_type' => $payment_type,
            'conversion_rate' => $currencyRate->rate,
            'original_rate' => $originalRate,
            'original_rate_conversion' => $originalCurrencyRate->rate,
            'original_rate_currency' => $getData->currency,
            'net_rate' => $netRate,
            'net_rate_currency' => $netCurrency,
        ];

        $bookingSaveData = Booking::create($bookingData);
        $gentingBooking = GentingBooking::create($gentingBookingData);
        $this->saveGentingRoomDetails($rooms, $gentingBooking->id);
        // dd($request->all(),$bookingData, $gentingBookingData,$rooms);
        try {

            DB::beginTransaction();
            // Approve the booking
            $gentingBooking->update(['approved' => true]);
            $gentingBooking->update(['total_cost' => $gentingPrice]);

            // Check if the authenticated user is an admin
            $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system
            // If created by admin, mark as approved
            if ($isCreatedByAdmin) {
                $bookingSaveData->update(['created_by_admin' => true, 'booking_type_id' => $gentingBooking->id]);
                $gentingBooking->update(['created_by_admin' => true, 'approved' => true, 'booking_id' => $bookingSaveData->id]);
            } else {
                $gentingBooking->update(['booking_id' => $bookingSaveData->id]);
                $bookingSaveData->update(['booking_type_id' => $gentingBooking->id]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback(); // Roll back if there's an error.

            Toast::title($e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);

            if ($request->ajax()) {
                return response()->json(['success' => false, 'message' => $e], 422);
            }

            return Redirect::back()->withErrors(['error' => $e]);
        }
        $bookingSaveData->load('user.company');
        //only use when difference is 12 hours
        if ($action === 'request_booking') {
            // Update booking approval status to pending
            $gentingBooking->approved = 0;
            $gentingBooking->sent_approval = 1;
            $admin = User::where('type', 'admin')->first();
            // Save the changes
            $gentingBooking->save();
            // Send approval pending email to the agent
            $agentEmail = auth()->user()->email;
            $agentName = auth()->user()->first_name;

            $bookingType = $bookingSaveData->booking_type;
            $mailInstance = new BookingApprovalPending($gentingBooking, $agentName, $bookingSaveData);
            SendEmailJob::dispatch($agentEmail, $mailInstance);
            $is_updated = null;
            $bookingData = $this->prepareBookingData($request, $gentingBooking, $is_updated);
            $mailInstance = new GentingBookingRequest($gentingBooking, $bookingData, $admin->first_name, $gentingBooking->gentingRate);
            SendEmailJob::dispatch($admin->email, $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_genting'), $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_info'), $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_account'), $mailInstance);
        } else {
            $is_updated = null;
            // Prepare data for PDF
            $bookingData = $this->prepareBookingData($request, $gentingBooking, $is_updated);
            $passenger_email = $request->input('passenger_email_address');
            $hirerEmail = $user->email;

            // Create and send PDF
            $this->createBookingPDF($bookingData, $hirerEmail, $request, $gentingBooking);

            if ($request->submitButton == "pay_offline") {

                Toast::title('Payment Done.')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
            }
        }
        session()->flash('success', 'Booking Request Submitted!');

        return response()->json(['success' => true, 'message' => 'Booking Created'], 200);
    }

    public function gentingData(mixed $request): array
    {
        // dd($request->all());
        return [
            'location_id' => $request->location_id,
            'check_in' => $request->check_in,
            'check_out' => $request->check_out,
            "package" => $request->package,
            "genting_rate_id" => $request->genting_rate_id,
            // "total_cost" => number_format($request->total_cost, 2),
            "hotel_name" => $request->hotel_name,
            "currency" => $request->currency,
            "user_id" => auth()->id(),
            "booking_date" => now()->format('Y-m-d H:i:s'),
            "room_capacity" => $request->room_capacity,
            "room_type" => $request->room_type,
            "number_of_rooms" => $request->number_of_rooms,
            "additional_adults" => $request->additional_adults,
            "additional_children" => $request->additional_children,
        ];
    }

    public function gentingFormValidationArray(Request $request): array
    {
        $rules = [
            "currency" => ['required'],
            "check_in" => ['required'],
            "check_out" => ['required'],
            // "total_cost" => ['required'],
        ];

        $passengers = $request->input('passengers', []);
        $messages = [];

        // Iterate over rooms and their passengers
        foreach ($passengers as $roomIndex => $roomPassengers) {
            foreach ($roomPassengers as $passengerIndex => $passenger) {
                // If it's the first passenger of the room, make nationality_id required
                if ($passengerIndex === 0) {
                    // Dynamically add the nationality_id rule for the first passenger of each room
                    $rules["passengers.$roomIndex.$passengerIndex.nationality_id"] = ['required'];

                    // Add custom message for first passenger nationality_id
                    $messages["passengers.$roomIndex.$passengerIndex.nationality_id.required"] = "Nationality is missing of first traveller of room " . ($roomIndex + 1) . ".";
                }
            }
        }

        return ['rules' => $rules, 'messages' => $messages];
    }


    public function saveGentingRoomDetails(array $rooms, $bookingID)
    {
        foreach ($rooms as $room) {
            $passengers = $room['passengers'] ?? [];
            $childAges = $room['child_ages'] ?? [];
            $adultCount = (int) ($room['adult_capacity'] ?? 0);
            $childCount = (int) ($room['child_capacity'] ?? 0);
            $bed = (bool) ($room['bed'] ?? false);

            // Use the first passenger's info for genting_room_details
            $firstPassenger = $passengers[0] ?? [];

            $roomDetail = GentingRoomDetail::create([
                'room_no' => $room['room_number'] ?? null,
                'booking_id' => $bookingID,
                'passenger_title' => $firstPassenger['title'] ?? null,
                'passenger_full_name' => $firstPassenger['full_name'] ?? null,
                'phone_code' => $firstPassenger['phone_code'] ?? null,
                'passenger_contact_number' => $firstPassenger['contact_number'] ?? null,
                'passenger_email_address' => $firstPassenger['email_address'] ?? null,
                'nationality_id' => $firstPassenger['nationality_id'] ?? null,
                'number_of_adults' => $adultCount,
                'number_of_children' => $childCount,
                'child_ages' => !empty($childAges) ? json_encode($childAges) : null,
            ]);

            // Save all passengers in genting_room_passenger_details
            $firstNationalityId = $firstPassenger['nationality_id'] ?? null;

            foreach ($passengers as $passenger) {
                $nationalityId = $passenger['nationality_id'] ?? $firstNationalityId;

                GentingRoomPassengerDetail::create([
                    'room_detail_id' => $roomDetail->id,
                    'passenger_title' => $passenger['title'] ?? null,
                    'passenger_full_name' => $passenger['full_name'] ?? null,
                    'phone_code' => $passenger['phone_code'] ?? null,
                    'passenger_contact_number' => $passenger['contact_number'] ?? null,
                    'passenger_email_address' => $passenger['email_address'] ?? null,
                    'nationality_id' => $nationalityId,
                ]);
            }
        }
    }


    public function calculateGentingSurcharges(string $surchargeJson, Carbon $checkIn, Carbon $checkOut): float
    {
        $totalSurcharge = 0;
        $appliedWeekendSurcharge = false;
        $appliedDateRangeSurcharge = false;

        $surcharges = json_decode($surchargeJson, true) ?? [];

        // Get weekend days
        $weekendDays = collect($surcharges)
            ->where('surcharge_type', 'weekend')
            ->pluck('surcharge_details.weekend')
            ->map(fn($day) => ucfirst(strtolower($day)))
            ->toArray();

        // Iterate through each night of stay
        $currentDate = clone $checkIn;
        while ($currentDate->lt($checkOut)) {
            $currentDay = $currentDate->format('l');
            $currentDateStr = $currentDate->toDateString();

            foreach ($surcharges as $surcharge) {
                $type = $surcharge['surcharge_type'];
                $details = $surcharge['surcharge_details'];

                if (
                    $type === 'weekend' &&
                    in_array($currentDay, $weekendDays) &&
                    !$appliedWeekendSurcharge
                ) {
                    $totalSurcharge += (float) $details['amount'];
                    $appliedWeekendSurcharge = true;

                } elseif (
                    $type === 'fixed_date' &&
                    $currentDateStr === $details['fixed_date']
                ) {
                    $totalSurcharge += (float) $details['amount'];

                } elseif (
                    $type === 'date_range' &&
                    !$appliedDateRangeSurcharge
                ) {
                    $startDate = Carbon::parse($details['start_date']);
                    $endDate = Carbon::parse($details['end_date']);

                    if ($currentDate->between($startDate, $endDate)) {
                        $totalSurcharge += (float) $details['amount'];
                        $appliedDateRangeSurcharge = true;
                    }
                }
            }

            $currentDate->addDay();
        }
        return $totalSurcharge;
    }

    public function calculateAdditionalBreakfast($breakfastModel, array $roomDetails): float
    {
        $adultPrice = (float) $breakfastModel->adult ?? 0;
        $childPrice = (float) $breakfastModel->child ?? 0;
        $totalAdults = 0;
        $totalChildren = 0;

        foreach ($roomDetails as $room) {
            $totalAdults += (int) $room['adult_capacity'] ?? 0;
            $totalChildren += is_array($room['child_ages'] ?? null)
                ? count($room['child_ages'])
                : ((int) $room['child_capacity'] ?? 0);
        }
        return ($totalAdults * $adultPrice) + ($totalChildren * $childPrice);
    }


}
