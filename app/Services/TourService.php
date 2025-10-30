<?php

namespace App\Services;

use App\Jobs\CreateBookingPDFJob;
use App\Jobs\SendEmailJob;
use App\Jobs\TourBookingPDFJob;
use App\Mail\BookingApprovalPending;
use App\Mail\BookingApprovalPendingAdmin;
use App\Mail\BookingMail;
use App\Mail\BookingMailToAdmin;
use App\Mail\Tour\TourBookingInvoiceMail;
use App\Mail\Tour\TourBookingVoucherMail;
use App\Mail\Tour\TourVoucherToAdminMail;
use App\Mail\Tour\TransferBookingInvoiceMail;
use App\Models\DiscountVoucher;
use App\Models\DiscountVoucherUser;
use App\Models\VoucherRedemption;
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
use App\Models\Tour;
use App\Models\Location;
use App\Models\TourDestination;
use App\Models\TourRate;
use App\Models\User;
use App\Models\Configuration;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Toast;

class TourService
{

    protected $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    public function extractRequestParameters(Request $request)
    {
        // dd($request->all());
        return [
            'travel_date' => $request->input('travel_date'),
            'pick_time' => $request->input('pick_time') ?? '00:00',
            'location' => $request->input('location'),
            'adult_capacity' => $request->input('adult_capacity') ?? 1,
            'child_capacity' => $request->input('child_capacity'),
            'infant_capacity' => $request->input('infant_capacity'),
            'child_age' => $request->input('child_age', []), // Correctly retrieves child_age[] as an array
            'price' => $request->input('price'),
            'destination' => $request->input('destination'),
            'hour' => $request->input('hour'),
            'package' => $request->input('package'),
            'currency' => $request->input('currency'),
        ];
    }



    // public function buildQuery(array $parameters)
    // {
    //     // Start the query on TourRate model
    //     $query = TourRate::query();

    //     // Add the necessary fields to select from both tour_rates and tour_destinations
    //     $query->addSelect(
    //         'tour_rates.*',
    //         'tour_destinations.name as tour_name',
    //         'tour_destinations.location_id',
    //         'tour_destinations.adult',
    //         'tour_destinations.child',
    //         'tour_destinations.ticket_currency',
    //         'tour_destinations.ticket_title'
    //     );

    //     // Join the tour_destinations table based on the foreign key relationship
    //     $query->join('tour_destinations', 'tour_rates.tour_destination_id', '=', 'tour_destinations.id');

    //     $user = auth()->user();
    //     // Add the wishlist logic
    //     if ($user) {
    //         $query->selectRaw(
    //             "(SELECT COUNT(*) FROM wishlists WHERE wishlists.rate_id = tour_rates.id AND wishlists.user_id = ? AND wishlists.type = 'tour') > 0 AS wishlist_item",
    //             [$user->id]
    //         );
    //     } else {
    //         $query->selectRaw('0 AS wishlist_item');
    //     }

    //     // Check if `travel_date` is provided in the parameters
    //     if (!empty($parameters['travel_date'])) {
    //         // Parse the day of the week from `travel_date`
    //         $tourDay = Carbon::parse($parameters['travel_date'])->format('l'); // Example: "Friday"

    //         // Filter out tours closed on the parsed day
    //         $query->where(function ($subQuery) use ($tourDay) {
    //             $subQuery->where('closing_day', 'NOT LIKE', "%$tourDay%")
    //                 ->orWhere('closing_day', '=', 'none'); // Handle 'none' as a special case
    //         });
    //     }


    //     // Base price and price per adult/child calculation
    //     if (!empty($parameters['adult_capacity']) || !empty($parameters['child_capacity'])) {
    //         $adultCount = $parameters['adult_capacity'] ?? 0;
    //         $childCount = $parameters['child_capacity'] ?? 0;

    //         $query->selectRaw(
    //             "tour_rates.price + (tour_destinations.adult * ?) + (tour_destinations.child * ?) AS total_price",
    //             [$adultCount, $childCount]
    //         )->selectRaw(
    //             "(tour_destinations.adult * ?) + (tour_destinations.child * ?) AS total_ticketprice",
    //             [$adultCount, $childCount]
    //         );

    //         // Optionally filter tours by budget if provided
    //         if (!empty($parameters['budget'])) {
    //             $query->having('total_price', '<=', $parameters['budget']);
    //         }

    //         // Sort tours by total price in ascending order
    //         $query->orderBy('total_price', 'asc');
    //     }

    //     // Filter by travel location
    //     if (!empty($parameters['location'])) {
    //         $location = Location::where('name', $parameters['location']['name'])->first();
    //         if ($location) {
    //             $query->where('tour_destinations.location_id', $location->id);
    //         }
    //     }

    //     // Filter by seating capacity
    //     if (!empty($parameters['adult_capacity']) && !empty($parameters['child_capacity'])) {
    //         $totalCapacity = $parameters['adult_capacity'] + $parameters['child_capacity'];
    //         $query->where('tour_rates.seating_capacity', '>=', $totalCapacity);
    //     } elseif (!empty($parameters['adult_capacity'])) {
    //         $query->where('tour_rates.seating_capacity', '>=', $parameters['adult_capacity']);
    //     } elseif (!empty($parameters['child_capacity'])) {
    //         $query->where('tour_rates.seating_capacity', '>=', $parameters['child_capacity']);
    //     }

    //     // Filter by luggage capacity
    //     if (!empty($parameters['luggage_capacity'])) {
    //         $query->where('tour_rates.luggage_capacity', '>=', $parameters['luggage_capacity']);
    //     }

    //     return $query;
    // }

    public function buildQuery(array $parameters)
    {
        $query = TourDestination::query();

        // Select columns from tour_destinations
        $query->select(
            'tour_destinations.id',
            'tour_destinations.name as tour_name',
            'tour_destinations.location_id',
            'tour_destinations.adult',
            'tour_destinations.child',
            'tour_destinations.description',
            'tour_destinations.highlights',
            'tour_destinations.important_info',
            'tour_destinations.images',
            'tour_destinations.closing_day',
            'tour_destinations.time_slots',
            'tour_destinations.on_request',
            'tour_destinations.ticket_currency',
            'tour_destinations.ticket_title',
            'tour_destinations.sharing',
            'tour_destinations.closing_start_date',
            'tour_destinations.closing_end_date',
        );

        // Join tour_rates but allow destinations without packages using LEFT JOIN
        $query->leftJoin('tour_rates', 'tour_destinations.id', '=', 'tour_rates.tour_destination_id');

        // Include tour_rates details if available
        $query->addSelect(
            'tour_rates.id as package_id',
            'tour_rates.package',
            'tour_rates.seating_capacity',
            'tour_rates.luggage_capacity',
            'tour_rates.remarks',
            'tour_rates.effective_date',
            'tour_rates.expiry_date',
            'tour_rates.tour_destination_id',
            'tour_rates.price',
            'tour_rates.currency',
            'tour_rates.sharing'
        );

        $user = auth()->user();

        // Wishlist logic
        if ($user) {
            $query->selectRaw(
                "(SELECT COUNT(*) FROM wishlists WHERE wishlists.rate_id = tour_rates.id AND wishlists.user_id = ? AND wishlists.type = 'tour') > 0 AS wishlist_item",
                [$user->id]
            );
        } else {
            $query->selectRaw('0 AS wishlist_item');
        }

        // // Handle travel date filtering based on closing days
        // if (!empty($parameters['travel_date'])) {
        //     $tourDay = Carbon::parse($parameters['travel_date'])->format('l'); // Example: "Friday"
        //     $query->where(function ($subQuery) use ($tourDay) {
        //         $subQuery->where('tour_destinations.closing_day', 'NOT LIKE', "%$tourDay%")
        //             ->orWhere('tour_destinations.closing_day', '=', 'none'); // Handle 'none' as a special case
        //     });
        // }

        // Handle pricing logic based on sharing
        if (!empty($parameters['adult_capacity']) || !empty($parameters['child_capacity'])) {
            $adultCount = $parameters['adult_capacity'] ?? 0;
            $childCount = $parameters['child_capacity'] ?? 0;

            $query->selectRaw("
        CASE 
            WHEN tour_rates.sharing = 1 
            THEN (IFNULL(tour_rates.price, 0) * (? + ?)) + (tour_destinations.adult * ?) + (tour_destinations.child * ?)
            ELSE IFNULL(tour_rates.price, 0) + (tour_destinations.adult * ?) + (tour_destinations.child * ?)
        END AS total_price",
                [$adultCount, $childCount, $adultCount, $childCount, $adultCount, $childCount]
            );

            $query->selectRaw("
        CASE 
            WHEN tour_rates.sharing = 1 
            THEN (tour_destinations.adult * ?) + (tour_destinations.child * ?)
            ELSE (tour_destinations.adult * ?) + (tour_destinations.child * ?)
        END AS total_ticketprice",
                [$adultCount, $childCount, $adultCount, $childCount]
            );

            // $query->orderBy('total_price', 'asc');
        }


        // Filter by location
        if (!empty($parameters['location'])) {
            $location = Location::where('name', $parameters['location']['name'])->first();
            if ($location) {
                $query->where('tour_destinations.location_id', $location->id);
            }
        }

        // Filter by seating capacity
        if (!empty($parameters['adult_capacity']) || !empty($parameters['child_capacity'])) {
            $totalCapacity = ($parameters['adult_capacity'] ?? 0) + ($parameters['child_capacity'] ?? 0);

            $query->selectRaw("
                    CASE 
                        WHEN tour_rates.seating_capacity IS NULL OR tour_rates.seating_capacity >= ? 
                        THEN 1 
                        ELSE 0 
                    END AS is_available
                ", [$totalCapacity]);
        }

        // Filter by luggage capacity
        if (!empty($parameters['luggage_capacity'])) {
            $query->where(function ($q) use ($parameters) {
                $q->where('tour_rates.luggage_capacity', '>=', $parameters['luggage_capacity'])
                    ->orWhereNull('tour_rates.luggage_capacity'); // Allow null luggage capacity for tickets
            });
        }

        return $query;
    }




    public function applyFiltersAndPaginate($query, array $parameters)
    {
        // Filter by price range
        if (!empty($parameters['price'])) {
            $priceRange = $this->extractPriceRange($parameters['price']);
            if ($priceRange) {
                $currentCurrency = $parameters['currency'] ?? 'MYR'; // Default to MYR if no currency is provided
                $convertedPriceRange = $this->convertPriceRangeToMYR($priceRange, $currentCurrency);
                if ($convertedPriceRange) {
                    $query->whereBetween('price', $convertedPriceRange);
                }
            }
        }

        // Filter by hours
        if (!empty($parameters['hour'])) {
            $query->where('hours', $parameters['hour']);
        }

        // Filter by package
        if (!empty($parameters['package'])) {
            $query->whereIn('package', $parameters['package']);
        }

        // Filter by location_id
        if (!empty($parameters['location_id'])) {
            $query->where('location_id', $parameters['location_id']);
        }

        // Filter by destination
        if (!empty($parameters['destination'])) {
            $fullDestination = trim($parameters['destination']);
            $words = explode(' ', $fullDestination);
            $partialMatch = implode(' ', array_slice($words, 0, 3)); // Adjust for 1-3 words if needed

            // First, do the exact match check
            $query->where(function ($q) use ($fullDestination, $partialMatch) {
                $q->whereRaw('TRIM(name) = ?', [$fullDestination])
                    ->orWhere('name', 'like', $partialMatch . '%');
            });

            //Exact match on the first
            $query->orderByRaw("
            CASE
                WHEN TRIM(name) = ? THEN 0 
                ELSE 1
            END, price ASC
        ", [$fullDestination]);
        }

        // Sort results dynamically
        $query->orderBy('price', 'ASC');

        // Fetch all results (we will manually paginate after grouping)
        $results = $query->get();

        // Group by tour name
        $groupedResults = $results->groupBy('tour_name');

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


    public function adjustTours($rates, array $parameters)
    {
        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        if (!empty($parameters['travel_date'])) {
            $tourDay = Carbon::parse($parameters['travel_date'])->format('l'); // e.g., "Monday"
            $tourDay = strtolower($tourDay);
            
        } else {
            $tourDay = null;
        }

        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        foreach ($rates as $tourName => $group) {
            foreach ($group as $rate) {
                // Apply currency conversion
                $rate->total_price = $this->applyCurrencyConversion($rate->total_price, $rate->currency ?? $rate->ticket_currency, $parameters['currency']);
                $rate->total_ticketprice = $this->applyCurrencyConversion($rate->total_ticketprice, $rate->currency ?? $rate->ticket_currency, $parameters['currency']);
                $rate->adult = $this->applyCurrencyConversion($rate->adult, $rate->currency ?? $rate->ticket_currency, $parameters['currency']);
                $rate->child = $this->applyCurrencyConversion($rate->child, $rate->currency ?? $rate->ticket_currency, $parameters['currency']);
                // Loop through all adjustment rates
                foreach ($adjustmentRates as $adjustmentRate) {
                    if ($adjustmentRate->transaction_type === 'tour') {
                        $rate->total_price = round($this->applyAdjustment($rate->total_price, $adjustmentRate), 2);
                        $rate->total_ticketprice = round($this->applyAdjustment($rate->total_ticketprice, $adjustmentRate), 2);
                        $rate->adult = round($this->applyAdjustment($rate->adult, $adjustmentRate), 2);
                        $rate->child = round($this->applyAdjustment($rate->child, $adjustmentRate), 2);
                    }
                }
                // Calculate is_close
                $rate->is_close = 0; // default

                if ($tourDay && isset($rate)) {
                    // Check closing days first
                    if (!empty($rate->closing_day)) {
                        $closingDays = json_decode($rate->closing_day, true);
                        if (is_array($closingDays)) {
                            $closingDays = array_map('strtolower', $closingDays); // normalize
                            if (in_array(strtolower($tourDay), $closingDays)) {
                                $rate->is_close = 1;
                            }
                        }
                    }

                    // Check closing date range
                    if (!empty($rate->closing_start_date) && !empty($rate->closing_end_date)) {

                        if ($parameters['travel_date'] >= $rate->closing_start_date && $parameters['travel_date'] <= $rate->closing_end_date) {
                            $rate->is_close = 1; // If within range, force close
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
                $destinations = TourDestination::where('location_id', $locationId)
                    ->pluck('name');
            } else {
                // If the location doesn't exist, set an empty collection
                $destinations = collect([]);
            }
        } else {
            // If searchLocation is empty, return all or handle as needed
            $destinations = collect([]);
        }

        // Fetch distinct packages directly from TourRate
        $vehicleTypes = TourRate::distinct()->pluck('package');

        // Extract total_price from $adjustedRates
        $prices = $adjustedRates->flatMap(function ($group) {
            return $group->pluck('total_price');
        })->map(fn($price) => (int) $price)->unique()->values();
        return [
            'destinations' => $destinations,
            'vehicleTypes' => $vehicleTypes,
            'prices' => $prices,
        ];
    }



    // private function applySurcharge($rate, $surcharge)
    // {
    //     return $surcharge ? $rate + ($rate * ($surcharge->surcharge_percentage / 100)) : $rate;
    // }

    public function prepareBookingData(Request $request, $tourBooking, $is_updated = 0)
    {
        // dd($tourBooking->flight_departure_time);
        $user = User::where('id', $tourBooking->user_id)->first() ?? auth()->user();

        // Retrieve admin and agent logos from the Company table
        $adminLogo = public_path('/img/logo.png');

        // First get the agent_code of the current user
        $agentCode = $user->agent_code;

        $timezone_abbreviation = 'UTC'; // fallback
        $timezones = json_decode($tourBooking->location->country->timezones);
        if (is_array($timezones) && isset($timezones[0]->abbreviation)) {
            $timezone_abbreviation = $timezones[0]->abbreviation;
        }

        // Then find the actual agent user who owns this agent_code
        $agent = User::where('type', 'agent')->where('agent_code', $agentCode)->first();

        $agentLogo = null;

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
        $currency = $request->input('currency') ?? $tourBooking->currency;
        $basePrice = $currency . ' ' . $tourBooking->total_cost;
        $rateId = $tourBooking->tour_rate_id;
        $tourRate = TourRate::where('id', $rateId)->first();
        $remarks = $tourRate->remarks ?? '';
        $phone = $agent->phone ?? $user->phone;
        $phoneCode = $agent->phone_code ?? $user->phone_code;
        $hirerEmail = $agent->email ?? $user->email;
        $hirerName = ($agent ?? $user)->first_name . ' ' . ($agent ?? $user)->last_name;
        $booking_status = Booking::where('id', $tourBooking->booking_id)->first();
        $hirerPhone = $phoneCode . $phone;
        // Assign to/from locations based on these values
        $location = Location::where(
            'id',
            $tourBooking->location_id
        )->value('name');

        $bookingDate = convertToUserTimeZone($tourBooking->created_at, 'F j, Y H:i T') ?? convertToUserTimeZone($request->input('booking_date'), 'F j, Y H:i T');
        $pickupAddress = $tourBooking->pickup_address ?? $request->input('pickup_address');
        $dropoffAddress = $tourBooking->dropoff_address ?? $request->input('dropoff_address');
        $child = $request->input('number_of_children') ?? $tourBooking->number_of_children;
        $adults = $request->input('number_of_adults') ?? $tourBooking->number_of_adults;
        $infants = $request->input('number_of_infants') ?? $tourBooking->number_of_infants;
        $driver_phone_code = $request->input('driver_phone_code') ?? optional($tourBooking->driver)->phone_code ?? '';
        $driver_phone_number = $request->input('driver_phone_number') ?? optional($tourBooking->driver)->phone_number ?? '';

        $tourDest = TourDestination::find($request->tour_destination_id);
        $adultRate = $tourDest->adult ?? 0;
        $childRate = $tourDest->child ?? 0;

        // if ($request->is_ticket || $tourBooking->type == 'ticket') {
        //     $netRate = ($adults * $adultRate) + ($child * $childRate);
        //     $netCurrency = $tourDest->ticket_currency ?? '';
        // } else {
        //     $ticket = ($adults * $adultRate) + ($child * $childRate);
        //     $netRate = ($tourRate->price) + $ticket ?? 0;
        //     $netCurrency = $tourRate->currency ?? '';
        // }
        $paymentMode = $tourBooking->booking->payment_type;

        $voucherRedeem = VoucherRedemption::where('booking_id', $booking_status->id)->first();
        $discount = 0;
        $discountedPrice = 0;
        if ($voucherRedeem) {
            $discount = $voucherRedeem->discount_amount;
            $discountedPrice = str_replace(',', '', $tourBooking->total_cost) - $discount;
            // dd(str_replace(',', '', $tourBooking->total_cost),$discount);
        }

        return [
            'id' => $tourBooking->id,
            'booking_id' => $booking_status->booking_unique_id,
            'locationName' => $location,
            'passenger_full_name' => $request->input('passenger_full_name') ?? $tourBooking->passenger_full_name,
            'passenger_contact_number' => $request->filled('passenger_contact_number')
                ? ($request->input('phone_code') ?? $tourBooking->phone_code) . ' ' . preg_replace('/^0/', '', $request->input('passenger_contact_number'))
                : (
                    $tourBooking->passenger_contact_number
                    ? ($request->input('phone_code') ?? $tourBooking->phone_code) . ' ' . preg_replace('/^0/', '', $tourBooking->passenger_contact_number)
                    : null
                ),
            'passenger_email_address' => $request->input('passenger_email_address') ?? $tourBooking->passenger_email_address,
            'booking_date' => $bookingDate,
            'tour_date' => Carbon::parse($tourBooking->tour_date)->format('F j, Y'),
            'pick_time' => Carbon::parse($tourBooking->pickup_time)->format('H:i') . ' ' . $timezone_abbreviation,
            'base_price' => $basePrice,
            'hours' => $tourBooking->hours ?? $request->input('hours'),
            'tour_name' => $request->input('tour_name') ?? $tourBooking->tour_name,
            'seating_capacity' => $request->input('seating_capacity') ?? $tourBooking->seating_capacity,
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
            'agentLogoUrl' => $agentLogoUrl,
            'child_ages' => json_decode($request->input('child_ages')) ?? json_decode($tourBooking->child_ages),
            'is_updated' => $is_updated,
            'created_by_admin' => $tourBooking->created_by_admin ?? false,
            'updated_at' => convertToUserTimeZone($request->input('updated_at'), 'F j, Y H:i T') ?? convertToUserTimeZone($tourBooking->updated_at, 'F j, Y H:i T'),
            'type' => $tourBooking->type ?? $request->input('type'),
            'package' => $tourBooking->package ?? $request->input('package'),
            'booking_slot' => $tourBooking->booking_slot ?? $request->input('booking_slot'),
            'driver_name' => $request->input('name') ?? optional($tourBooking->driver)->name ?? '',
            'driver_number' => $driver_phone_code . '' . $driver_phone_number,
            'vehicle_no' => $request->input('car_no') ?? optional($tourBooking->driver)->car_no ?? '',
            'netRate' => $booking_status->net_rate,
            'netCurrency' => $booking_status->net_rate_currency,
            'paymentMode' => $paymentMode,
            'reservation_id' => $tourBooking->reservation_id ?? null,
            'discountedPrice' => $discountedPrice,
            'discount' => $discount,
            'voucher' => $voucherRedeem ? $voucherRedeem->voucher : null,
            'currency' => $currency,
            'voucher_code' => $request->voucher_code,
        ];
    }

    public function createBookingPDF($bookingData, $email, Request $request, $tourBooking)
    {
        $user = User::where('id', $tourBooking->user_id)->first();
        TourBookingPDFJob::dispatch($bookingData, $email, $tourBooking, $user);

        return true;
        $passengerName = $user->first_name . ' ' . $user->last_name;

        $directoryPath = public_path("bookings");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015
        $id = $tourBooking->id;
        $pdfFilePathVoucher = "{$directoryPath}/tour_booking_voucher_{$timestamp}_{$id}.pdf";
        $pdfFilePathInvoice = "{$directoryPath}/tour_booking_invoice_{$timestamp}_{$id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/tour_booking_admin_voucher_{$timestamp}_{$id}.pdf";

        // Load the view and save the PDF
        $pdf = Pdf::loadView('email.tour.tour_booking_voucher', $bookingData);
        $pdf->save($pdfFilePathVoucher);
        // booking_voucher
        $pdf = Pdf::loadView('email.tour.tour_booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);
        //voucher to admin
        $pdf = Pdf::loadView('email.tour.tour_booking_admin_voucher', $bookingData);
        $pdf->save($pdfFilePathAdminVoucher);
        $cc = [config('mail.notify_tour'), config('mail.notify_info'), config('mail.notify_account')];

        $mailInstance = new TourBookingVoucherMail($bookingData, $pdfFilePathInvoice, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to($email)->send(new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName));
        // $mailInstance = new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName);
        // SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to(['tours@grtravel.net', 'info@grtravel.net'])->send(new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName));
        // $email = ['tours@grtravel.net', 'info@grtravel.net'];
        // $mailInstance = new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName);
        // SendEmailJob::dispatch($email, $mailInstance);
    }

    public function sendVoucherEmail($request, $tourBooking, $is_updated = 0)
    {
        $bookingData = $this->prepareBookingData($request, $tourBooking, $is_updated);
        $user = User::where('id', $tourBooking->user_id)->first();
        $passengerName = $user->first_name . ' ' . $user->last_name;
        $directoryPath = public_path("bookings");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015

        if ($tourBooking->type === 'ticket') {
            if ($tourBooking->booking->booking_status === 'vouchered') {
                $pdfFilePathInvoice = "{$directoryPath}/ticket_invoice_paid_{$timestamp}_{$tourBooking->booking_id}.pdf";
            } else {
                $pdfFilePathInvoice = "{$directoryPath}/ticket_booking_invoice_{$timestamp}_{$tourBooking->booking_id}.pdf";
            }
            $pdfFilePathVoucher = "{$directoryPath}/ticket_booking_voucher_{$timestamp}_{$tourBooking->booking_id}.pdf";
            $pdfFilePathAdminVoucher = "{$directoryPath}/ticket_booking_admin_voucher_{$timestamp}_{$tourBooking->booking_id}.pdf";
        } else {
            if ($tourBooking->booking->booking_status === 'vouchered') {
                $pdfFilePathInvoice = "{$directoryPath}/tour_invoice_paid_{$timestamp}_{$tourBooking->booking_id}.pdf";
            } else {
                $pdfFilePathInvoice = "{$directoryPath}/tour_booking_invoice_{$timestamp}_{$tourBooking->booking_id}.pdf";
            }
            $pdfFilePathVoucher = "{$directoryPath}/tour_booking_voucher_{$timestamp}_{$tourBooking->booking_id}.pdf";
            $pdfFilePathAdminVoucher = "{$directoryPath}/tour_booking_admin_voucher_{$timestamp}_{$tourBooking->booking_id}.pdf";
        }
        // booking_voucher


        $pdf = Pdf::loadView('email.tour.tour_booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);

        $pdfVoucher = Pdf::loadView('email.tour.tour_booking_voucher', $bookingData);
        $pdfVoucher->save($pdfFilePathVoucher);

        $pdfAdmin = Pdf::loadView('email.tour.tour_booking_admin_voucher', $bookingData);
        $pdfAdmin->save($pdfFilePathAdminVoucher);

        $email = $user->email;

        // $isCreatedByAdmin = $tourBooking->created_by_admin; // to track creation by admin

        // Mail::to($email)->send(new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName));
        $mailInstance = new TourBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to($email)->send(new BookingMail($bookingData, $pdfFilePathVoucher, $passengerName));
        $mailInstance = new TourBookingVoucherMail($bookingData, $pdfFilePathVoucher, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to(['tours@grtravel.net', 'info@grtravel.net'])->send(new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName));
        if (auth()->user()->type !== 'admin') {
            $emailAdmin1 = config('mail.notify_tour');
            $emailAdmin2 = config('mail.notify_info');
            $emailAdmin3 = config('mail.notify_account');
            $mailInstance = new TourVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin1, $mailInstance);
            $mailInstance = new TourVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin2, $mailInstance);
            $mailInstance = new TourVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
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

    public function storeBooking(Request $request)
    {
        $data = $request->all();
        $request = new Request($data);
        $payment_type = '';
        $subtotal = 0;
        // Retrieve location names
        // Fetch flight details for both departure and arrival
        $adults = $request->number_of_adults;
        $children = $request->number_of_children;
        $tourType = TourRate::with('tourDestination')->where('id', $request->input('tour_rate_id'))->firstOrFail();
        if ($tourType->sharing === 1) {
            // Multiply price per person when sharing is enabled
            $tour_price = ($tourType->price * $adults) + ($tourType->price * $children) + ($tourType->tourDestination->adult * $adults) + ($tourType->tourDestination->child * $children);
        } else {
            // Use the standard pricing method
            $tour_price = $tourType->price + ($tourType->tourDestination->adult * $adults) + ($tourType->tourDestination->child * $children);
        }
        $agentCode = auth()->user()->agent_code;

        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');

        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        $tour_price = $this->applyAdjustment($tour_price, $adjustmentRate);
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            // Filter the adjustment rates for transaction_type === 'transfer'
            $tourRates = $adjustmentRate->filter(function ($rate) {
                return $rate->transaction_type === 'tour';
            });

            foreach ($tourRates as $tourRate) {
                // Pass the individual adjustment rate object
                $tour_price = $this->applyAdjustment($tour_price, $tourRate);
            }
        }
        $net_price = $tour_price;
        $tour_price = $this->applyCurrencyConversion($tour_price, $tourType->currency, $request->currency);

        $location_name = Location::where('id', $tourType->location_id)->value('name');
        // Prepare fleet booking data and save
        $tourBookingData = $this->tourData($request, $tour_price);

        $booking_status = 'confirmed';
        // Retrieve the Rate based on relevant criteria

        // $tour_price = $tourType->price;

        $booking_currency = $request->input('currency') ?? $tourType->currency;
        $tourPrice = round((float) str_replace(',', '', $tourBookingData['total_cost']) ?? 0, 2);
        $subtotal = $tourPrice;
        $currencyRate = CurrencyRate::where('target_currency', $request->currency)->first();
        $netCurrencyRate = CurrencyRate::where('target_currency', $tourType->currency)->first();
        // $tourBookingData['conversion_rate'] = $currencyRate ?? null;
        // Call the deductCredit method to handle credit deduction for the agent
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

                if (!is_null($voucher->min_booking_amount) && $tourPrice <= $voucher->min_booking_amount) {
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
                        $discountPrice = ($voucher->value / 100) * $tourPrice; // Convert booking amount to USD
                        if (!is_null($voucher->max_discount_amount) && $discountPrice > $voucher->max_discount_amount) {
                            $discountPrice = $voucher->max_discount_amount;
                        }
                    }
                    $discountedTourPrice = max(0, $tourPrice - $discountPrice);
                }
            }

            $deductionResult = $this->bookingService->deductCredit($user, $discountedTourPrice ? $discountedTourPrice : $tourPrice, $booking_currency);
            if ($deductionResult !== true) {
                return response()->json([
                    'success' => false,
                    'error' => 'Insufficient credit limit to create booking.',
                ], 400); // Return HTTP 400 for a bad request
            }
        }
        if ($request->submitButton == "pay_offline" || $request->pay_offline) {

            $booking_status = 'vouchered';
            $payment_type = 'wallet';
        }

        if ($request->submitButton == "pay_online" || $request->pay_online) {

            $booking_status = 'confirmed';
            $payment_type = 'card';
        }

        $cancellation = CancellationPolicies::where('active', 1)->where('type', 'tour')->first();
        $maxDeadlineDate = null;

        if ($cancellation) {

            $cancellationPolicyData = json_decode($cancellation->cancellation_policies_meta, true);

            $cancellationPolicyCollection = collect($cancellationPolicyData);

            // Sort by 'days_before' in ascending order
            $sortedCancellationPolicy = $cancellationPolicyCollection->sortBy('days_before')->values()->toArray();
            // dd($request->input('tour_date') . ' ' . $request->input('tour_time') . ":00");
            $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $request->input('tour_date') . ' ' . $request->input('pickup_time')); // Replace with your target date and time
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

        $action = $request->input('submitButton'); // This will be 'request_booking'
        if ($action === 'request_booking') {
            $booking_status = 'pending_approval';
        }
        $tourDest = TourDestination::find($request->tour_destination_id);
        $adultRate = $tourDest->adult ?? 0;
        $childRate = $tourDest->child ?? 0;

        $ticket = ($adults * $adultRate) + ($children * $childRate);
        $netRate = ($tourType->price) + $ticket ?? 0;
        $netCurrency = $tourType->currency ?? '';

        $bookingData = [
            'agent_id' => auth()->id(),
            'user_id' => auth()->id(),
            'booking_date' => now()->format('Y-m-d H:i:s'),
            'amount' => $tourPrice,
            'currency' => $booking_currency,
            'service_date' => $request->input('tour_date') . ' ' . $request->input('pickup_time'),
            // 'deadline_date' => $booking_status === 'vouchered' ? now()->format('Y-m-d H:i:s') : $maxDeadlineDate,
            'deadline_date' => $maxDeadlineDate,
            'booking_type' => 'tour',
            'booking_status' => $booking_status,
            'payment_type' => $payment_type,
            'subtotal' => $subtotal,
            'conversion_rate' => $currencyRate->rate,
            'original_rate' => $net_price,
            'original_rate_conversion' => $netCurrencyRate->rate,
            'original_rate_currency' => $tourType->currency,
            'net_rate' => $netRate,
            'net_rate_currency' => $netCurrency,
        ];


        try {

            DB::beginTransaction();
            $bookingSaveData = Booking::create($bookingData);
            $tourBooking = TourBooking::create($tourBookingData);

            if ($request->submitButton == "pay_offline" && $request->get('voucher_code') != null && $voucher) {
                $this->storeVoucherRedemptions($voucher, $user, $bookingSaveData->id, $discountPrice);
            }
            // Approve the booking
            $tourBooking->update(['approved' => true]);
            $tourBooking->update(['total_cost' => $tourPrice]);
            // Check if the authenticated user is an admin
            $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system
            // If created by admin, mark as approved
            if ($isCreatedByAdmin) {
                $bookingSaveData->update(['created_by_admin' => true, 'booking_type_id' => $tourBooking->id]);
                $tourBooking->update(['created_by_admin' => true, 'approved' => true, 'booking_id' => $bookingSaveData->id]);
            } else {
                $tourBooking->update(['booking_id' => $bookingSaveData->id]);
                $bookingSaveData->update(['booking_type_id' => $tourBooking->id]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback(); // Roll back if there's an error.

            Toast::title($e->getMessage())
                ->danger()
                ->rightBottom()
                ->autoDismiss(5);

            if ($request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return Redirect::back()->withErrors(['error' => $e->getMessage()]);
        }
        //only use when difference is 12 hours
        if ($action === 'request_booking') {
            // Update booking approval status to pending
            $tourBooking->approved = 0;
            $tourBooking->sent_approval = 1;
            $admin = User::where('type', 'admin')->first();
            // Save the changes
            $tourBooking->save();
            // Send approval pending email to the agent
            $agentEmail = auth()->user()->email;
            $agentName = auth()->user()->first_name;

            $bookingType = $bookingSaveData->booking_type;
            $mailInstance = new BookingApprovalPending($tourBooking, $agentName, $bookingSaveData);
            SendEmailJob::dispatch($agentEmail, $mailInstance);

            $mailInstance = new BookingApprovalPendingAdmin($tourBooking, $bookingSaveData, $admin->first_name, $bookingType);
            SendEmailJob::dispatch($admin->email, $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_tour'), $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_info'), $mailInstance);
            SendEmailJob::dispatch(config('mail.notify_account'), $mailInstance);
        } else {
            $is_updated = null;
            // Prepare data for PDF
            $bookingData = $this->prepareBookingData($request, $tourBooking, $is_updated);
            $passenger_email = $request->input('passenger_email_address');
            $hirerEmail = auth()->user()->email;

            // Create and send PDF
            $this->createBookingPDF($bookingData, $hirerEmail, $request, $tourBooking);
            if ($request->submitButton == "pay_online") {
                return response()->json([
                    'success' => true,
                    'message' => 'pay_online',
                    'redirect_url' => $this->processPayment($bookingSaveData),
                ]);
            }

            // if ($request->submitButton == "pay_offline") {
            //     return response()->json([
            //         'success' => true,
            //         'message' => 'Payment Done',
            //         'redirect_url' => route('tourbookings.index'),
            //     ]);
            // }
        }
        session()->flash('success', 'Booking successfully created!');

        return response()->json(['success' => true, 'message' => 'Booking Created', 'redirect_url' => route('tourbookings.index')], 200);
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

    public function tourData(mixed $request, $tour_price): array
    {
        $pickupAddress = $request->pickup_address;
        $dropoffAddress = $request->dropoff_address;

        // Check if pickup_address is an array or a string
        if (is_array($pickupAddress)) {
            // If it's an array, you can access the 'name' from it
            $pickupAddressName = $pickupAddress['name'] ?? null;
        } else {
            // If it's a string (just the address), use it directly
            $pickupAddressName = $pickupAddress;
        }

        // Check if pickup_address is an array or a string
        if (is_array($dropoffAddress)) {
            // If it's an array, you can access the 'name' from it
            $dropoffAddressName = $dropoffAddress['name'] ?? null;
        } else {
            // If it's a string (just the address), use it directly
            $dropoffAddressName = $dropoffAddress;
        }

        return [
            'location_id' => $request->location_id,
            'seating_capacity' => $request->seating_capacity,
            'passenger_full_name' => $request->passenger_full_name,
            'passenger_contact_number' => $request->passenger_contact_number,
            'phone_code' => $request->phone_code,
            'passenger_email_address' => $request->passenger_email_address,
            'tour_time' => $request->tour_time,
            "tour_date" => $request->tour_date,
            "package" => $request->package,
            "pickup_address" => $pickupAddressName,
            "dropoff_address" => $dropoffAddressName,
            "pickup_time" => $request->pickup_time,
            "special_request" => $request->special_request,
            "tour_rate_id" => $request->tour_rate_id,
            "tour_destination_id" => $request->tour_destination_id,
            "total_cost" => $tour_price,
            "hours" => $request->hours,
            "tour_name" => $request->tour_name,
            "number_of_adults" => $request->number_of_adults,
            "number_of_children" => $request->number_of_children,
            "number_of_infants" => $request->number_of_infants,
            "child_ages" => $request->child_ages,
            "nationality_id" => $request->nationality_id,
            "currency" => $request->currency,
            "user_id" => auth()->user()->id,
            "booking_date" => now()->format('Y-m-d H:i:s'),
            "booking_slot" => $request->booking_slot,
        ];
    }

    public function tourFormValidationArray(Request $request): array
    {
        return [
            "passenger_full_name" => ['required',],
            "passenger_email_address" => ['nullable',],
            "currency" => ['required',],
            "agent_id" => ['nullable',],
            "booking_date" => ['nullable',],
            "tour_date" => ['required',],
            "pickup_address" => ['required',],
            "dropoff_address" => ['required',],
            "tour_rate_id" => ['nullable',],
            // "total_cost" => ['required',],
            "nationality_id" => ['required',],
            "seating_capacity" => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    public function printVoucher($id)
    {
        // Retrieve the booking by its ID
        $tourBooking = TourBooking::find($id);
        $booking = Booking::where('id', $tourBooking->booking_id)->first();
        if (!$tourBooking) {
            // Return a JSON response with an error message if booking is not found
            return response()->json(['error' => 'Voucher not found.'], Response::HTTP_NOT_FOUND);
        }
        // Get the created_at date and format it as Ymd (e.g., 20241030)
        $createdDate = $tourBooking->updated_at->format('Ymd');
        // Generate the file name using the created date and booking ID
        if ($booking->booking_type === 'ticket') {
            if (Auth::user()->type === 'admin') {
                $fileName = 'ticket_booking_admin_voucher_' . $createdDate . '_' . $booking->id . '.pdf';
            } else {
                $fileName = 'ticket_booking_voucher_' . $createdDate . '_' . $booking->id . '.pdf';
            }
        } else {
            if (Auth::user()->type === 'admin') {
                $fileName = 'tour_booking_admin_voucher_' . $createdDate . '_' . $booking->id . '.pdf';
            } else {
                $fileName = 'tour_booking_voucher_' . $createdDate . '_' . $booking->id . '.pdf';
            }
        }
        $filePath = public_path('bookings/' . $fileName);

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return response()->json(['error' => 'Voucher file not found.'], Response::HTTP_NOT_FOUND);
    }

    public function printInvoice($id)
    {
        // Retrieve the booking by its ID
        $tourBooking = TourBooking::find($id);
        $booking = Booking::where('id', $tourBooking->booking_id)->first();
        if (!$tourBooking) {
            // Return a JSON response with an error message if booking is not found
            return response()->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        // Get the created_at date and format it as Ymd (e.g., 20241030)
        $createdDate = $tourBooking->updated_at->format('Ymd');

        if ($booking->booking_status === 'vouchered') {
            // Generate the file name using the created date and booking ID
            if ($booking->booking_type === 'ticket') {
                $fileName = 'ticket_invoice_paid_' . $createdDate . '_' . $booking->id . '.pdf';
            } else {
                $fileName = 'tour_invoice_paid_' . $createdDate . '_' . $booking->id . '.pdf';
            }
            $filePath = public_path('bookings/' . $fileName);
        } else {
            // Generate the file name using the created date and booking ID
            if ($booking->booking_type === 'ticket') {
                $fileName = 'ticket_booking_invoice_' . $createdDate . '_' . $booking->id . '.pdf';
            } else {
                $fileName = 'tour_booking_invoice_' . $createdDate . '_' . $booking->id . '.pdf';
            }
            $filePath = public_path('bookings/' . $fileName);
        }

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return response()->json(['error' => 'Invoice file not found in the directory.'], Response::HTTP_NOT_FOUND);
    }

    public function updatePassenger(Request $request, TourBooking $booking)
    {
        // Validate the incoming data
        $request->validate([
            'passenger_full_name' => 'required|string|max:255',
            'passenger_email_address' => 'nullable|email|max:255',
            'passenger_contact_number' => 'nullable',
        ]);

        $pickupAddress = $request->input('pickup_address');
        if (is_array($pickupAddress)) {
            // If it's an array, you can access the 'name' from it
            $pickupAddressName = $pickupAddress['name'] ?? null;
        } else {
            // If it's a string (just the address), use it directly
            $pickupAddressName = $pickupAddress;
        }
        $request->merge(['pickup_address' => $pickupAddressName]);

        $dropoffAddress = $request->input('dropoff_address');
        if (is_array($dropoffAddress)) {
            // If it's an array, you can access the 'name' from it
            $dropoffAddressName = $dropoffAddress['name'] ?? null;
        } else {
            // If it's a string (just the address), use it directly
            $dropoffAddressName = $dropoffAddress;
        }
        $request->merge(['dropoff_address' => $dropoffAddressName]);

        // Update the booking with validated data
        $booking->update([
            'passenger_full_name' => $request->input('passenger_full_name'),
            'passenger_email_address' => $request->input('passenger_email_address'),
            'passenger_contact_number' => $request->input('passenger_contact_number'),
            'pickup_address' => $request->input('pickup_address'),
            'dropoff_address' => $request->input('dropoff_address'),
            'pickup_time' => $request->input('pickup_time'),
            'nationality_id' => $request->input('nationality_id'),
            'special_request' => $request->input('special_request'),
            'phone_code' => $request->input('phone_code'),
        ]);
        $is_updated = 1;
        $this->sendVoucherEmail($request, $booking, $is_updated);

        // Show a success message and redirect back
        return $booking;
    }

    public function storeTicket(Request $request)
    {
        $data = $request->all();
        $payment_type = '';
        $subtotal = 0;
        $request = new Request($data);
        $tourType = TourDestination::with('tourRates')->where('id', $request->input('tour_destination_id'))->firstOrFail();
        $agentCode = auth()->user()->agent_code;
        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');
        $adults = $request->number_of_adults;
        $children = $request->number_of_children;
        $tour_price = $tourType->adult * $adults + $tourType->child * $children;
        $adjustmentRate = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId);
        if ($adjustmentRate && $adjustmentRate->isNotEmpty()) {
            $tourRates = $adjustmentRate->filter(function ($rate) {
                return $rate->transaction_type === 'tour';
            });
            foreach ($tourRates as $tourRate) {
                $tour_price = $this->applyAdjustment($tour_price, $tourRate);
            }
        }
        $net_price = $tour_price;
        $tour_price = $this->applyCurrencyConversion($tour_price, $tourType->ticket_currency, $request->currency);
        $currencyRate = CurrencyRate::where('target_currency', $request->currency)->first();
        $netCurrencyRate = CurrencyRate::where('target_currency', $tourType->ticket_currency)->first();
        // Prepare tour booking data and save
        $tourBookingData = $this->ticketData($request);

        $booking_status = 'confirmed';

        $booking_currency = $request->input('currency');

        // $tourPrice = $tourBookingData['total_cost'] ?? 0;
        $tourPrice = $tour_price;
        $subtotal = $tourPrice;
        // Call the deductCredit method to handle credit deduction for the agent
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

                if (!is_null($voucher->min_booking_amount) && $tourPrice <= $voucher->min_booking_amount) {
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
                        $discountPrice = ($voucher->value / 100) * $tourPrice; // Convert booking amount to USD
                        if (!is_null($voucher->max_discount_amount) && $discountPrice > $voucher->max_discount_amount) {
                            $discountPrice = $voucher->max_discount_amount;
                        }
                    }
                    $discountedTourPrice = max(0, $tourPrice - $discountPrice);
                }
            }
            $deductionResult = $this->bookingService->deductCredit($user, $discountedTourPrice ? $discountedTourPrice : $tourPrice, $booking_currency);
            if ($deductionResult !== true) {
                return response()->json([
                    'success' => false,
                    'error' => 'Insufficient credit limit to create booking.',
                ], 400); // Return HTTP 400 for a bad request
            }
        }
        if ($request->submitButton == "pay_offline" || $request->pay_offline) {

            $booking_status = 'vouchered';
            $payment_type = 'wallet';

        }

        if ($request->submitButton == "pay_online" || $request->pay_online) {

            $booking_status = 'confirmed';
            $payment_type = 'card';

        }

        $cancellation = CancellationPolicies::where('active', 1)->where('type', 'tour')->first();
        $maxDeadlineDate = null;

        if ($cancellation) {
            $cancellationPolicyData = json_decode($cancellation->cancellation_policies_meta, true);
            $cancellationPolicyCollection = collect($cancellationPolicyData);

            // Sort by 'days_before' in ascending order
            $sortedCancellationPolicy = $cancellationPolicyCollection->sortBy('days_before')->values()->toArray();

            $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $request->input('tour_date') . ' ' . $request->input('pickup_time'));
            $currentDate = Carbon::now();

            // Calculate the difference in days
            $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' allows negative values if past
            $remainingDays = $remainingDays ?? 0;

            $maxDeadlineDay = 0;
            foreach ($sortedCancellationPolicy as $policy) {
                if ($remainingDays >= $policy['days_before']) {
                    $maxDeadlineDay = $policy['days_before'];
                    break;
                }
            }

            // If no valid policy was found, meaning remaining days are less than the minimum policy days_before
            if ($maxDeadlineDay == 0) {
                $maxDeadlineDate = $currentDate->format('Y-m-d H:i:s'); // Set to the current date
            } else {
                $maxDeadlineDate = $targetDate->subDays($maxDeadlineDay)->format('Y-m-d H:i:s');
            }
        }

        $action = $request->input('submitButton'); // This will be 'request_booking'
        if ($action === 'request_booking') {
            $booking_status = 'pending_approval';
        }
        $tourDest = TourDestination::find($request->tour_destination_id);
        $adultRate = $tourDest->adult ?? 0;
        $childRate = $tourDest->child ?? 0;
        $netRate = ($adults * $adultRate) + ($children * $childRate);
        $netCurrency = $tourDest->ticket_currency ?? '';
        $bookingData = [
            'agent_id' => auth()->id(),
            'user_id' => auth()->id(),
            'booking_date' => now()->format('Y-m-d H:i:s'),
            'amount' => $tourPrice,
            'currency' => $booking_currency,
            'service_date' => $request->input('tour_date') . ' ' . $request->input('pickup_time'),
            // 'deadline_date' => $booking_status === 'vouchered' ? now()->format('Y-m-d H:i:s') : $maxDeadlineDate,
            'deadline_date' => $maxDeadlineDate,
            'booking_type' => 'ticket',
            'booking_status' => $booking_status,
            'payment_type' => $payment_type,
            'subtotal' => $subtotal,
            'conversion_rate' => $currencyRate->rate,
            'original_rate' => $net_price,
            'original_rate_conversion' => $netCurrencyRate->rate,
            'original_rate_currency' => $tourType->ticket_currency,
            'net_rate' => $netRate,
            'net_rate_currency' => $netCurrency,
        ];

        try {

            DB::beginTransaction();

            $bookingSaveData = Booking::create($bookingData);
            $tourBooking = TourBooking::create($tourBookingData);
            // If the user is paying offline and has a voucher code, store the voucher redemption
            if ($request->submitButton == "pay_offline" && $request->get('voucher_code') != null && $voucher) {
                $this->storeVoucherRedemptions($voucher, $user, $bookingSaveData->id, $discountPrice);
            }

            // Approve the booking
            $tourBooking->update(['approved' => true]);
            $tourBooking->update(['total_cost' => $tourPrice]);
            // Check if the authenticated user is an admin
            $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system
            // If created by admin, mark as approved
            if ($isCreatedByAdmin) {
                $bookingSaveData->update(['created_by_admin' => true, 'booking_type_id' => $tourBooking->id]);
                $tourBooking->update(['created_by_admin' => true, 'approved' => true, 'booking_id' => $bookingSaveData->id]);
            } else {
                $tourBooking->update(['booking_id' => $bookingSaveData->id]);
                $bookingSaveData->update(['booking_type_id' => $tourBooking->id]);
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
        //only use when difference is 12 hours
        if ($action === 'request_booking') {
            // Update booking approval status to pending
            $tourBooking->approved = 0;
            $tourBooking->sent_approval = 1;
            $admin = User::where('type', 'admin')->first();
            // Save the changes
            $tourBooking->save();
            // Send approval pending email to the agent
            $agentEmail = auth()->user()->email;
            $agentName = auth()->user()->first_name;

            $bookingType = $bookingSaveData->booking_type;
            $mailInstance = new BookingApprovalPending($tourBooking, $agentName, $bookingSaveData);
            SendEmailJob::dispatch($agentEmail, $mailInstance);

            $mailInstance = new BookingApprovalPendingAdmin($tourBooking, $bookingSaveData, $admin->first_name, $bookingType);
            SendEmailJob::dispatch($admin->email, $mailInstance);
        } else {
            $is_updated = null;
            // Prepare data for PDF
            $bookingData = $this->prepareBookingData($request, $tourBooking, $is_updated);
            $passenger_email = $request->input('passenger_email_address');
            $hirerEmail = auth()->user()->email;

            // Create and send PDF
            $this->createBookingPDF($bookingData, $hirerEmail, $request, $tourBooking);
            if ($request->submitButton == "pay_online") {
                return response()->json([
                    'success' => true,
                    'message' => 'pay_online',
                    'redirect_url' => $this->processPayment($bookingSaveData),
                ]);
            }

            if ($request->submitButton == "pay_offline") {

                Toast::title('Payment Done.')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
            }
        }
        session()->flash('success', 'Booking successfully created!');

        return response()->json(['success' => true, 'message' => 'Booking Created', 'redirect_url' => route('tourbookings.index')], 200);
    }

    public function ticketData(mixed $request): array
    {
        $totalCost = str_replace(',', '', number_format($request->total_cost, 2));
        return [
            'location_id' => $request->location_id,
            'passenger_full_name' => $request->passenger_full_name,
            'passenger_contact_number' => $request->passenger_contact_number,
            'phone_code' => $request->phone_code,
            'passenger_email_address' => $request->passenger_email_address,
            'tour_time' => $request->tour_time,
            "tour_date" => $request->tour_date,
            "package" => $request->package,
            "tour_rate_id" => $request->tour_rate_id,
            "tour_destination_id" => $request->tour_destination_id,
            "total_cost" => $totalCost,
            "hours" => $request->hours,
            "tour_name" => $request->tour_name,
            "number_of_adults" => $request->number_of_adults,
            "number_of_children" => $request->number_of_children,
            "number_of_infants" => $request->number_of_infants,
            "child_ages" => $request->child_ages,
            "nationality_id" => $request->nationality_id,
            "currency" => $request->currency,
            "user_id" => auth()->user()->id,
            "booking_date" => now()->format('Y-m-d H:i:s'),
            "type" => 'ticket',
            'booking_slot' => $request->booking_slot,
        ];
    }

    public function ticketFormValidationArray(Request $request): array
    {
        return [
            "passenger_full_name" => ['required',],
            "passenger_email_address" => ['nullable',],
            "currency" => ['required',],
            "agent_id" => ['nullable',],
            "booking_date" => ['nullable',],
            "tour_date" => ['required', 'date', 'after_or_equal:today'],
            "tour_rate_id" => ['nullable',],
            // "total_cost" => ['required',],
            "nationality_id" => ['required',],
        ];
    }

    public function processPayment($bookingSaveData)
    {

        $merchantID = env('RAZER_MERCHANT_ID');
        $verifyKey = env('RAZER_VERIFY_KEY');
        $tax_percent = Configuration::getValue('razerpay', 'tax', 0);
        $subtotal = $bookingSaveData->amount;
        $tax_amount = $subtotal * ($tax_percent / 100);
        $tourPrice = $subtotal + $tax_amount;
        $vcode = md5($tourPrice . $merchantID . $bookingSaveData->id . $verifyKey);

        $company = auth()->user()->company()->with('country')->first();
        $country = $company->country->iso2 ?? 'MY';

        $payload = [
            'amount' => $tourPrice,
            'orderid' => $bookingSaveData->id,
            'currency' => $bookingSaveData->currency,
            'bill_name' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
            'bill_email' => auth()->user()->email,
            'bill_mobile' => auth()->user()->mobile,
            'bill_desc' => 'GR TOUR & TRAVEL - Tour Booking: ' . $bookingSaveData->id,
            'country' => $country,
            'vcode' => $vcode,
        ];

        return env('RAZER_SUBMIT_URL') . $merchantID . '/?' . http_build_query($payload);
    }
}
