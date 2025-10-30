<?php
namespace App\Services;
use App\Jobs\HotelBookingPDFJob;
use App\Jobs\SendEmailJob;
use App\Mail\BookingApprovalPending;
use App\Mail\Genting\GentingVoucherToAdminMail;
use App\Mail\Hotel\HotelBookingInvoiceMail;
use App\Mail\Hotel\HotelBookingVoucherMail;
use App\Mail\Hotel\HotelVoucherToAdminMail;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Hotel;
use App\Models\HotelBooking;
use App\Models\HotelRoomDetail;
use App\Models\HotelRoomPassengerDetail;
use App\Models\Location;
use App\Models\Country;
use App\Models\City;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\AgentPricingAdjustment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Toast;
use App\Services\RezliveTestCaseMatcherService;
use App\Services\BookingService;
use Illuminate\Support\Facades\Log;
use Exception;

class RezliveHotelService
{
   
    protected $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;

    }

    public function searchById(array $params)
    {
        $client = new Client();
        // $caseNumber = RezliveTestCaseMatcherService::detectCase($params);
        // session(['caseNumber' => $caseNumber]);
        
        $params = $this->enrichHotelParamsWithCityData($params);

        // dd($params);
        $hotelRequestXml = $this->buildRezliveRequestXml($params);
        // dd($this->agentCode, auth()->user());
        // if ($caseNumber) {
            // Log::info('HotelSearchRequest', ['caseNumber' => $caseNumber]);
        $user = auth()->user();
        $agentCode = $user?->agent_code ?? $user?->id ?? 'guest';
        logXml($agentCode, 'HotelSearchRequest', $hotelRequestXml);
        // }
        $response = $client->post('http://test.xmlhub.com/testpanel.php/action/findhotelbyid', [
            'headers' => [
                'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                'Accept' => 'application/xml',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'XML' => $hotelRequestXml,
            ]
        ]);

        $xmlResponse = simplexml_load_string($response->getBody()->getContents(), "SimpleXMLElement", LIBXML_NOCDATA);
        // if ($caseNumber) {
            // Log::info('HotelSearchResponse', ['caseNumber' => $caseNumber]);
        $user = auth()->user();
        $agentCode = $user?->agent_code ?? $user?->id ?? 'guest';
        logXml($agentCode, 'HotelSearchResponse', $xmlResponse);
        // }
        // Check if error exists
        // if (isset($xmlResponse->error)) {
        //     throw new Exception((string) $xmlResponse->error);
        // }
        // dd($xmlResponse);
        return $xmlResponse;
    }

    public function enrichHotelParamsWithCityData(array $params): array
    {
        $hotel = Hotel::where('rezlive_hotel_code', $params['id'] ?? $params['hotel_id'] ?? null)->first();

        if ($hotel) {
            $city = City::find($hotel->city_id);

            if ($city) {
                $params['city_id'] = $city->rezlive_code;
                $params['city'] = $city->rezlive_code;
                $params['country_code'] = $city->country_code;
            }
        }

        return $params;
    }

    public function getCacellationPolicy(array $params)
    {

        // $user = auth()->user();
        // $agentCode = $user?->agent_code ?? $user?->id ?? 'guest';
        // $cancellationRequestXml = $this->buildRezliveCancellationPolicyRequest($params);
        // logXml($agentCode, 'CancellationRequest', $cancellationRequestXml);
        $params = $this->enrichHotelParamsWithCityData($params);
        $client = new Client();
        $response = $client->post('http://test.xmlhub.com/testpanel.php/action/getcancellationpolicy', [
            'headers' => [
                'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                'Accept' => 'application/xml',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'XML' => $this->buildRezliveCancellationPolicyRequest($params),
            ]
        ]);

        $cancellationPolicy = simplexml_load_string($response->getBody()->getContents(), "SimpleXMLElement", LIBXML_NOCDATA);
        // logXml($agentCode, 'CancellationResponse', $cancellationPolicy);
        $agentId = auth()->id();
        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId, 'hotel');
        if ($cancellationPolicy && isset($cancellationPolicy->CancellationInformations)) {

            foreach ($cancellationPolicy->CancellationInformations->CancellationInformation as $policy) {
                
                if($policy->ChargeType != 'Percentage'){
                    $chargeAmount = $policy->ChargeAmount;
                    $policy_currency = $policy->Currency;
                    $covertedAmount = $this->applyCurrencyConversion((float) $chargeAmount, (string) $policy_currency, $params['currency']);
                    foreach ($adjustmentRates as $adjustmentRate) {
                        $policy->ChargeAmount = round($this->applyAdjustment($covertedAmount, $adjustmentRate), 2);
                        $policy->Currency = $params['currency'];
                    }
                    
                }
            }
               
        }
        // dd(simplexml_load_string($response->getBody()->getContents(), "SimpleXMLElement", LIBXML_NOCDATA));
        return $cancellationPolicy;
    }

    public function search(array $params)
    {
        // dd($this->getHotelsByCityId(11));]
       
        $check_in = Carbon::parse($params['check_in'])->format('d/m/Y');
        $check_out = Carbon::parse($params['check_out'])->format('d/m/Y');
        $client = new Client();
        $response = $client->post('http://test.xmlhub.com/testpanel.php/action/findhotel', [
            'headers' => [
                'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                'Accept' => 'application/xml',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'XML' => $this->findHotelRequestXml([
                    'check_in' => $check_in,
                    'check_out' => $check_out,
                    'country_code' => $params['country_code'],
                    'city_id' => $params['city_code'],
                    'guest_nationality' => $params['nationality'],
                    'adults' => $params['adults'] ?? [],
                    'children' => $params['children'] ?? [],
                    'child_ages' => $params['child_age'] ?? [],
                    'rooms' => $params['rooms'] ?? 1,
                    'agent_code' => 'CD33410',
                    'user_name' => 'Ibrahimaftab',
                ]),
            ]
        ]);

        $xml = simplexml_load_string($response->getBody()->getContents(), "SimpleXMLElement", LIBXML_NOCDATA);
        
        $current_currency = (string) ($xml->Currency ?? 'MYR');
        $params['current_currency'] = $current_currency;
        $hotelsArray = json_decode(json_encode($xml->Hotels), true);
        $hotels = collect($hotelsArray['Hotel'] ?? []);
        $currentPage = request()->get('page', 1);
        $perPage = 20;


        $pagedItems = $hotels->forPage($currentPage, $perPage);
        
        $convertedItems = $this->adjustHotel($pagedItems , $params);
       
        // $convertedItems = $pagedItems->map(function ($hotel) use ($params) {
            
        //     $price = $hotel['Price'] ?? 0;
        //     $convertedPrice = $this->applyCurrencyConversion($price, $params['current_currency'], $params['currency']); // Assuming prices in USD
        //     $hotel['ConvertedPrice'] = $convertedPrice;
        //     return $hotel;
        // });


        $pagedData = new LengthAwarePaginator(
            $convertedItems,
            $hotels->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        // foreach ($xml->Hotels->Hotel as $hotel) {
        //     echo (string) $hotel->Name . "<br>"; // Force cast to string to get CDATA content
        // }

        return $pagedData;
    }

    public function preBooking(array $params)
    {
        $params = $this->enrichHotelParamsWithCityData($params);
        // dd($params);
        $caseNumber = session('caseNumber', null);
        $check_in = Carbon::parse($params['check_in'])->format('d/m/Y');
        $check_out = Carbon::parse($params['check_out'])->format('d/m/Y');
        $prebookRequest = $this->preBookingRequestXml([
            'session_id' => $params['session_id'],
            'hotel_id' => $params['hotel_id'],
            'booking_key' => $params['booking_key'],
            'price' => $params['price'],
            'currency' => $params['currency'],
            'room_type' => $params['room_type'],
            'check_in' => $check_in,
            'check_out' => $check_out,
            'country_code' => $params['country_code'],
            'city_id' => $params['city'],
            'guest_nationality' => $params['nationality'],
            'adults' => $params['adults'] ?? [],
            'children' => $params['children'] ?? [],
            'child_ages' => $params['child_ages'] ?? [],
            'rooms' => $params['rooms'] ?? 1,
            'agent_code' => 'CD33410',
            'user_name' => 'Ibrahimaftab',
            'rez_child_ages' => $params['rez_child_ages']
        ]);

        // if ($caseNumber) {
            // Log::info('PreBookRequest', ['caseNumber' => $caseNumber]);
        $user = auth()->user();
        $agentCode = $user?->agent_code ?? $user?->id ?? 'guest';
        logXml($agentCode, 'PreBookRequest', $prebookRequest);
        // }


        $client = new Client();
        $response = $client->post('http://test.xmlhub.com/testpanel.php/action/prebook', [
            'headers' => [
                'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                'Accept' => 'application/xml',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'XML' => $prebookRequest,
            ]
        ]);

        $xmlResponse = simplexml_load_string($response->getBody()->getContents(), "SimpleXMLElement", LIBXML_NOCDATA);
        // if ($caseNumber) {
            // Log::info('PreBookResponse', ['caseNumber' => $caseNumber]);
        $user = auth()->user();
        $agentCode = $user?->agent_code ?? $user?->id ?? 'guest';
        logXml($agentCode, 'PreBookResponse', $xmlResponse);
        // }
        // dd($response->getBody()->getContents());
        return $xmlResponse;
    }

    public function hotelBooking(array $params)
    {
        $params = $this->enrichHotelParamsWithCityData($params);
        $caseNumber = session('caseNumber', null);
        $check_in = Carbon::parse($params['check_in'])->format('d/m/Y');
        $check_out = Carbon::parse($params['check_out'])->format('d/m/Y');
        $hotelBookRequest = $this->bookingRequestXml([
            'session_id' => $params['session_id'],
            'hotel_id' => $params['hotel_id'],
            'hotel_name' => $params['hotel_name'],
            'booking_key' => $params['booking_key'],
            'price' => $params['price'],
            'priceDifference' => $params['priceDifference'],
            'beforeAfterPrice' => $params['beforeAfterPrice'],
            'currency' => $params['currency'],
            'room_type' => $params['room_type'],
            'check_in' => $check_in,
            'check_out' => $check_out,
            'country_code' => $params['country_code'],
            'city_id' => $params['city'],
            'guest_nationality' => $params['nationality'],
            'adults' => $params['adults'] ?? [],
            'children' => $params['children'] ?? [],
            'child_ages' => $params['child_ages'] ?? [],
            'child_age' => $params['child_age'] ?? [],
            'rooms' => $params['rooms'] ?? 1,
            'salutation' => $params['title'],
            'first_name' => $params['first_name'],
            'last_name' => $params['last_name'],
            'agent_code' => 'CD33410',
            'user_name' => 'Ibrahimaftab',
            'totalCapacityPerRoom' => $params['totalCapacityPerRoom'],
        ]);

        // if ($caseNumber) {
            // Log::info('HotelBookRequest', ['caseNumber' => $caseNumber]);
        $user = auth()->user();
        $agentCode = $user?->agent_code ?? $user?->id ?? 'guest';
        logXml($agentCode, 'HotelBookRequest', $hotelBookRequest);
        // }
        $client = new Client();
        $response = $client->post('http://test.xmlhub.com/testpanel.php/action/bookhotel', [
            'headers' => [
                'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                'Accept' => 'application/xml',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'XML' => $hotelBookRequest,
            ]
        ]);

        $xmlResponse = simplexml_load_string($response->getBody()->getContents(), "SimpleXMLElement", LIBXML_NOCDATA);
        // if ($caseNumber) {
            // Log::info('HotelBookResponse', ['caseNumber' => $caseNumber]);
        $user = auth()->user();
        $agentCode = $user?->agent_code ?? $user?->id ?? 'guest';
        logXml($agentCode, 'HotelBookResponse', $xmlResponse);
        // }
        // dd($response->getBody()->getContents());
        return $xmlResponse;
    }


    public function buildRezliveRequestXml(array $params): string
    {
        $xml = new \SimpleXMLElement('<HotelFindRequest/>');
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', 'CD33410');
        $auth->addChild('UserName', 'Ibrahimaftab');

        $booking = $xml->addChild('Booking');
        $booking->addChild('ArrivalDate', $params['check_in']);
        $booking->addChild('DepartureDate', $params['check_out']);
        $booking->addChild('CountryCode', $params['country_code']);
        $booking->addChild('City', $params['city_id']);

        $hotelIds = $booking->addChild('HotelIDs');
        if (is_array($params['id'])) {
            foreach ($params['id'] as $id) {
                $hotelIds->addChild('Int', $id);
            }
        } else {
            $hotelIds->addChild('Int', $params['id']);
        }

        $booking->addChild('GuestNationality', $params['guest_nationality']);

        $rooms = $booking->addChild('Rooms');
        // $room = $booking->addChild('Room');
        foreach (range(1, $params['rooms']) as $r) {
            $room = $rooms->addChild('Room');
            $room->addChild('Type', 'Room-' . $r);
            $room->addChild('NoOfAdults', $params['adults'][$r] ?? 0);
            $room->addChild('NoOfChilds', $params['children'][$r] ?? 0);

            $childrenAges = $room->addChild('ChildrenAges');
            if (!empty($params['child_ages'][$r])) {
                foreach ($params['child_ages'][$r] as $age) {
                    $childrenAges->addChild('ChildAge', $age);
                }
            }
        }

        return $xml->asXML();
    }

    private function buildRezliveCancellationPolicyRequest(array $params): string
    {
        $xml = new \SimpleXMLElement('<CancellationPolicyRequest/>');

        // Authentication
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', $params['agent_code'] ?? 'XXXXX');
        $auth->addChild('UserName', $params['user_name'] ?? 'XXXXX');

        // Booking Details
        $xml->addChild('ArrivalDate', Carbon::parse($params['arrival_date'])->format('d/m/Y')); // Format: dd/MM/yyyy
        $xml->addChild('DepartureDate', Carbon::parse($params['departure_date'])->format('d/m/Y')); // Format: dd/MM/yyyy
        $xml->addChild('HotelId', $params['hotel_id']);
        $xml->addChild('CountryCode', $params['country_code']);
        $xml->addChild('City', $params['city_id']);
        $xml->addChild('GuestNationality', $params['guest_nationality']);
        $xml->addChild('Currency', $params['currency']);

        // Room Details
        $roomDetails = $xml->addChild('RoomDetails');
        foreach ($params['rooms'] as $room) {
            // dd($room['children_ages']);
            $roomDetail = $roomDetails->addChild('RoomDetail');
            $roomDetail->addChild('BookingKey', $room['booking_key']);
            $roomDetail->addChild('Adults', $room['adults']);
            $roomDetail->addChild('Children', $room['children']);
            // $childrenAges = (empty($room['children_ages']) || !is_array($room['children_ages'])) ? '0' : implode(',', $room['children_ages']);
            $childrenAges = '0';

            if (!empty($room['children_ages']) && is_array($room['children_ages'])) {
                // Flatten nested children ages array
                $flattened = [];
                foreach ($room['children_ages'] as $ages) {
                    if (is_array($ages)) {
                        $flattened = array_merge($flattened, $ages);
                    }
                }
                $childrenAges = implode(',', $flattened);
            }
            $roomDetail->addChild('ChildrenAges', $childrenAges);
            $roomDetail->addChild('Type', $room['type']);
        }

        return $xml->asXML();
    }

    public function findHotelRequestXml(array $params): string
    {   
        $xml = new \SimpleXMLElement('<HotelFindRequest/>');
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', $params['agent_code'] ?? 'XXXXX');
        $auth->addChild('UserName', $params['user_name'] ?? 'XXXXX');
        $booking = $xml->addChild('Booking');
        $booking->addChild('ArrivalDate', $params['check_in']);
        $booking->addChild('DepartureDate', $params['check_out']);
        $booking->addChild('CountryCode', $params['country_code']);
        $booking->addChild('City', $params['city_id']);
        $booking->addChild('GuestNationality', $params['guest_nationality']);
        $ratings = $booking->addChild('HotelRatings');
        foreach (range(1, 5) as $rating) {
            $ratings->addChild('HotelRating', $rating);
        }
        $rooms = $booking->addChild('Rooms');
        // dd($params, range(1, $params['rooms']));
        // $check = 'Test';
        foreach (range(1, $params['rooms']) as $r) {
            $room = $rooms->addChild('Room');
            $room->addChild('Type', 'Room-' . $r);
            $room->addChild('NoOfAdults', $params['adults'][$r]);
            $room->addChild('NoOfChilds', $params['children'][$r]);
            $childrenAges = $room->addChild('ChildrenAges');
            
            // $check = $check.'-'.$r;
            if ($params['children'][$r] > 0) {

                foreach ($params['child_ages'][$r] as $age) {

                    $childrenAges->addChild('ChildAge', $age);
                }
            }

        }
        // dd($check);

        return $xml->asXML();

    }

    public function getHotelDetails(string $hotelId)
    {
        $client = new Client();

        $response = $client->post('http://test.xmlhub.com/testpanel.php/action/gethoteldetails', [
            'headers' => [
                'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                'Accept' => 'application/xml',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'XML' => $this->hotelDetailsRequestXml([
                    'hotel_id' => $hotelId,
                    'agent_code' => 'CD33410',
                    'user_name' => 'Ibrahimaftab',
                ]),
            ]
        ]);

        $xml = simplexml_load_string($response->getBody()->getContents(), "SimpleXMLElement", LIBXML_NOCDATA);
        $hotelDetails = json_decode(json_encode($xml), true);

        return $hotelDetails;
    }

    public function getBookingDetails(array $params)
    {
        try {
            $client = new Client();

            $response = $client->post('http://test.xmlhub.com/testpanel.php/action/getbookingdetails', [
                'headers' => [
                    'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                    'Accept' => 'application/xml',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'XML' => $this->bookingDetailsRequestXml([
                        'agent_code' => 'CD33410',
                        'user_name' => 'Ibrahimaftab',
                        'booking_id' => $params['booking_id'],
                        'booking_code' => $params['booking_code']
                    ]),
                ]
            ]);

            $rawResponse = $response->getBody()->getContents();
            $cleanedResponse = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;)/', '&amp;', $rawResponse);
            // dd($cleanedResponse);
            if (stripos(trim($rawResponse), '<') !== 0) {
                return [
                    'status' => false,
                    'error' => 'Invalid response from API'
                ];
            }
            
            $xml = simplexml_load_string($cleanedResponse, "SimpleXMLElement", LIBXML_NOCDATA);

            if (!$xml) {
                return [
                    'status' => false,
                    'error' => 'Failed to parse XML response'
                ];
            }

            return [
                'status' => true,
                'data' => $xml
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'error' => $e->getMessage()
            ];
        }
    }


    public function cancelHotelBooking(array $params)
    {
        $user = auth()->user();
        $agentCode = $user?->agent_code ?? $user?->id ?? 'guest';
        $cancellationHotelRequestXml = $this->cancelHotelBookingRequestXml([
                    'agent_code' => 'CD33410',
                    'user_name' => 'Ibrahimaftab',
                    'booking_id' => $params['booking_id'],
                    'booking_code' => $params['booking_code']
        ]);
        logXml($agentCode, 'CancellationHotelRequest', $cancellationHotelRequestXml);
        $client = new Client();

        $response = $client->post('http://test.xmlhub.com/testpanel.php/action/cancelhotel', [
            'headers' => [
                'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                'Accept' => 'application/xml',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'XML' => $cancellationHotelRequestXml,
            ]
        ]);

        $rawResponse = $response->getBody()->getContents();
        logXml($agentCode, 'CancellationHotelResponse', $rawResponse);
        $cleanedResponse = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;)/', '&amp;', $rawResponse);
        
        if (stripos(trim($rawResponse), '<') !== 0) {
            throw new \Exception("Invalid response received:\n" . $rawResponse);
        }
        
        return simplexml_load_string($cleanedResponse, "SimpleXMLElement", LIBXML_NOCDATA);
        
    }


    public function hotelDetailsRequestXml(array $params): string
    {
        $xml = new \SimpleXMLElement('<HotelDetailsRequest/>');
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', $params['agent_code']);
        $auth->addChild('UserName', $params['user_name']);

        $hotel = $xml->addChild('Hotels');
        $hotel->addChild('HotelId', $params['hotel_id']);

        return $xml->asXML();
    }
      public function HotelConfirmationRequestXml(array $params): string
    {
        $xml = new \SimpleXMLElement('<HotelConfirmationRequest/>');
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', $params['agent_code']);
        $auth->addChild('UserName', $params['user_name']);

        $hotel = $xml->addChild('Confirmation');
        $hotel->addChild('BookingId', $params['booking_id']);
        $hotel->addChild('BookingCode', $params['booking_code']);

        return $xml->asXML();
    }

    public function bookingDetailsRequestXml(array $params): string
    {
        $xml = new \SimpleXMLElement('<GetBookingRequest/>');
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', $params['agent_code']);
        $auth->addChild('UserName', $params['user_name']);
        $xml->addChild('BookingId', $params['booking_id']);
        $xml->addChild('BookingCode', $params['booking_code']);
        return $xml->asXML();
    }

    public function cancelHotelBookingRequestXml(array $params): string
    {
        $xml = new \SimpleXMLElement('<CancellationRequest/>');
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', $params['agent_code']);
        $auth->addChild('UserName', $params['user_name']);
        $cancel = $xml->addChild('Cancellation');
        $cancel->addChild('BookingId', $params['booking_id']);
        $cancel->addChild('BookingCode', $params['booking_code']);

        return $xml->asXML();
    }

    public function preBookingRequestXml(array $params): string
    {
        $xml = new \SimpleXMLElement('<PreBookingRequest/>');
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', $params['agent_code']);
        $auth->addChild('UserName', $params['user_name']);

        $hotel = $xml->addChild('PreBooking');
        $hotel->addChild('SearchSessionId', $params['session_id']);
        $hotel->addChild('ArrivalDate', $params['check_in']);
        $hotel->addChild('DepartureDate', $params['check_out']);
        $hotel->addChild('GuestNationality', $params['guest_nationality']);
        $hotel->addChild('CountryCode', $params['country_code']);
        $hotel->addChild('City', $params['city_id']);
        $hotel->addChild('HotelId', $params['hotel_id']);
        $hotel->addChild('Currency', $params['currency']);
        $roomDetail = $hotel->addChild('RoomDetails')->addChild('RoomDetail');
        $adultList = [];
        $childList = [];
        $childAgesList = [];
        
        foreach (range(1, $params['rooms']) as $r) {
            $adultList[] = $params['adults'][$r] ?? 0;
            $childList[] = $params['children'][$r] ?? 0;

            if (!empty($params['child_ages'][$r])) {
                // Flatten and implode child ages for this room
                $ages = array_values($params['child_ages'][$r]);
                $childAgesList[] = implode(',', $ages);
            } else {
                $childAgesList[] = '0';
            }
        }
        // dd(implode('|',json_decode($params['rez_child_ages'])));
        // dd($params, implode('|', $adultList) , implode('|', $childList), implode('|', $childAgesList));
        // Add values to RoomDetail as pipe-separated strings
        $roomDetail->addChild('Type', is_array($params['room_type']) ? implode('|', $params['room_type']) : $params['room_type']);
        $roomDetail->addChild('BookingKey', $params['booking_key']);
        $roomDetail->addChild('Adults', implode('|', $adultList));
        $roomDetail->addChild('Children', implode('|', $childList));
        $roomDetail->addChild('ChildrenAges', implode('|', json_decode($params['rez_child_ages'])));        // $roomDetail->addChild('ChildrenAges',implode('|', $params['rez_child_ages']));
        $roomDetail->addChild('TotalRooms', $params['rooms']);
        $roomDetail->addChild('TotalRate', is_array($params['price']) ? implode('|', $params['price']) : $params['price']);
        return $xml->asXML();
    }

    public function bookingRequestXml(array $params): string
    {
        // dd($params);
        $xml = new \SimpleXMLElement('<BookingRequest/>');
        $auth = $xml->addChild('Authentication');
        $auth->addChild('AgentCode', $params['agent_code']);
        $auth->addChild('UserName', $params['user_name']);

        $hotel = $xml->addChild('Booking');
        $hotel->addChild('SearchSessionId', $params['session_id']);
        $hotel->addChild('ArrivalDate', $params['check_in']);
        $hotel->addChild('DepartureDate', $params['check_out']);
        $hotel->addChild('GuestNationality', $params['guest_nationality']);
        $hotel->addChild('CountryCode', $params['country_code']);
        $hotel->addChild('City', $params['city_id']);
        $hotel->addChild('HotelId', $params['hotel_id']);
        $hotel->addChild('Name', $params['hotel_name']);
        $hotel->addChild('Currency', $params['currency']);
        $roomDetail = $hotel->addChild('RoomDetails')->addChild('RoomDetail');

        // Combine values for Adults, Children, and ChildrenAges
        $adultList = [];
        $childList = [];
        $childAgesList = [];
        
        foreach (range(1, $params['rooms']) as $r) {
            $adultList[] = $params['adults'][$r] ?? 0;
            $childList[] = $params['children'][$r] ?? 0;
            $child_ages = $params['child_ages'][$r];
            if (!empty($child_ages)) {
                $childAgesList[] = implode('*',$child_ages);
            } else {
                $childAgesList[] = '0';
            }
        }

        $prices = explode('|',$params['price']);
        $difference = (float) ($params['priceDifference'] ?? 0);

        if ( $difference != 0) {

            if(is_array($prices) && count($prices) >= 2 ){

                $total = array_sum($prices);
                $adjustedPrices = [];

                foreach ($prices as $price) {
                    $percent = $total > 0 ? $price / $total : 0;
                    $adjustedPrices[] = round($price + ($percent * $difference), 2); // Rounded to 2 decimals
                }

                $params['price'] = $adjustedPrices;

            }else {
                $params['price'] = round(((float) $prices[0] + $difference), 2);
            }
            
        }
        
        // dd($prices, $params['price']);
        // Add values to RoomDetail as pipe-separated strings
        $roomDetail->addChild('Type', is_array($params['room_type']) ? implode('|', $params['room_type']) : $params['room_type']);
        $roomDetail->addChild('BookingKey', $params['booking_key']);
        $roomDetail->addChild('Adults', implode('|', $adultList));
        $roomDetail->addChild('Children', implode('|', $childList));
        $roomDetail->addChild('ChildrenAges', implode('|', $childAgesList));
        $roomDetail->addChild('TotalRooms', $params['rooms']);
        $roomDetail->addChild('TotalRate', is_array($params['price']) ? implode('|', $params['price']) : $params['price']);
        $guestIndex = 1;
        foreach (range(1, $params['rooms']) as $roomIndex) {
            $guestsBlock = $roomDetail->addChild('Guests'); // Attach to RoomDetail instead of root

            foreach (range(1, $params['totalCapacityPerRoom'][$roomIndex]) as $i) {
                $guest = $guestsBlock->addChild('Guest');

                $salutation = $params['salutation'][$guestIndex];
                $guest->addChild('Salutation', $salutation);
                $guest->addChild('FirstName', $params['first_name'][$guestIndex]);
                $guest->addChild('LastName', $params['last_name'][$guestIndex]);

                if ($salutation === 'Child') {
                    $guest->addChild('IsChild', 1);
                    $guest->addChild('Age', $params['child_age'][$guestIndex]);
                }

                $guestIndex++;
            }
        }
        // dd($xml->asXML());
        return $xml->asXML();
    }

    public function applyCurrencyConversion($rate, $currentCurrency, $targetCurrency)
    {
        if ($targetCurrency) {
            $usdRate = CurrencyService::convertCurrencyToUsd($currentCurrency, $rate);
            return round(CurrencyService::convertCurrencyFromUsd($targetCurrency, $usdRate), 2);
        }
        return $rate;
    }

    /**
     * Get hotels by CityId from CSV file.
     *
     * @param string $cityId
     * @param string $filePath
     * @return array
     */
    private function getHotelsByCityId(string $cityId): array
    {
        $hotels = [];
        $csvPath = storage_path('hotels.csv');
        // dd($csvPath);
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return $hotels;
        }

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($line = fgetcsv($handle)) !== false) {
                $columns = explode('|', $line[0]);

                if (isset($columns[3]) && $columns[3] === $cityId) {
                    $hotels[] = [
                        'HotelCode' => $columns[0] ?? '',
                        'Name' => $columns[1] ?? '',
                        'City' => $columns[2] ?? '',
                        'CityId' => $columns[3] ?? '',
                        'CountryId' => $columns[4] ?? '',
                        'CountryCode' => $columns[5] ?? '',
                        'Rating' => $columns[6] ?? '',
                        'HotelAddress' => $columns[7] ?? '',
                        'HotelPostalCode' => $columns[8] ?? '',
                        'Latitude' => $columns[9] ?? '',
                        'Longitude' => $columns[10] ?? '',
                        'Desc' => $columns[11] ?? '',
                    ];
                }
            }
            fclose($handle);
        }

        return $hotels;
    }

    public function preBookingSubmission(Request $request)
    {
        $data = $request->all();
        if(!is_array($request->adult_capacity)){
        $adultCapacities = json_decode($request->adult_capacity, true);
        $childCapacities = json_decode($request->child_capacity, true);
        $childAges = json_decode($request->child_ages, true);
        }
        else{
            $adultCapacities = $request->adult_capacity;
            $childCapacities = $request->child_capacity;
            $childAges = $request->child_ages;
        }
        // dd($adultCapacities, $childCapacities, $childAges);
        $dataArray = [
            'hotel_id' => $request->hotel_id,
            'session_id' => $request->session_id,
            'booking_key' => $request->booking_key,
            'room_type' => $request->room_type,
            'check_in' => $request->check_in,
            'check_out' => $request->check_out,
            'nationality' => $request->nationality,
            'country_code' => $request->country_code,
            'city' => $request->city_id,
            'adults' => $adultCapacities,
            'children' => $childCapacities,
            'child_ages' => $childAges,
            'rooms' => (int) $request->rooms,
            'currency' => 'MYR',
            'price' => $request->actual_price,
            'rez_child_ages' => $request->rez_child_ages,
        ];

        $preBooking = $this->preBooking($dataArray);
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
            $bed = is_array($roomPassengersRaw) && isset($roomPassengersRaw['bed']) ? $roomPassengersRaw['bed'] : 0;
            $roomPassengers = collect($roomPassengersRaw)->filter(fn($item) => is_array($item))->values();

            return [
                'bed' => $bed,
                'passengers' => $roomPassengers
                    ->filter(fn($p) => !empty($p['first_name']) || !empty($p['last_name']) || !empty($p['phone_code']) || !empty($p['nationality_id']))
                    ->values()
                    ->toArray()
            ];
        })->filter(fn($room) => !empty($room['passengers']))->values()->toArray();

        foreach ($rooms as $index => &$room) {
            $room['passengers'] = $passengerData[$index]['passengers'] ?? [];
            $room['bed'] = $passengerData[$index]['bed'] ?? 0;
        }

        $hotelBookingData = $this->hotelData($request, $preBooking);

        $booking_status = 'confirmed';
        $booking_currency = $request->input('currency');
        $bookingBeforePrice = $preBooking->PreBookingDetails->BookingBeforePrice ?? 0;
        $bookingAfterPrice = $preBooking->PreBookingDetails->BookingAfterPrice ?? 0;
        $difference = $preBooking->PreBookingDetails->Difference ?? 0;
        

        if ($request->currency !== 'MYR') {
            $bookingBeforePrice = $this->applyCurrencyConversion((float) $bookingBeforePrice, (string) $preBooking->PreBookingRequest->PreBooking->Currency, $request->currency);
            $bookingAfterPrice = $this->applyCurrencyConversion((float) $bookingAfterPrice, (string) $preBooking->PreBookingRequest->PreBooking->Currency, $request->currency);
            $difference = $this->applyCurrencyConversion((float) $difference, (string) $preBooking->PreBookingRequest->PreBooking->Currency, $request->currency);
            // $hotelPrice = number_format($converted, 2);
        }
        $agentId = auth()->id();
        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId, 'hotel');
        foreach ($adjustmentRates as $adjustmentRate) {
            $preBooking->PreBookingDetails->BookingBeforePrice = round($this->applyAdjustment($bookingBeforePrice, $adjustmentRate), 2);
            $preBooking->PreBookingDetails->BookingAfterPrice = round($this->applyAdjustment($bookingAfterPrice, $adjustmentRate), 2);
            $preBooking->PreBookingDetails->Difference = round($this->applyAdjustment($difference, $adjustmentRate), 2);
        }

        foreach ($preBooking->PreBookingRequest->PreBooking->CancellationInformations->CancellationInformation as $value) {
            if($value->ChargeType == 'Amount'){
                $chargeAmount = $this->applyCurrencyConversion((float) $value->ChargeAmount, (string) $value->Currency, $request->currency);
                foreach ($adjustmentRates as $adjustmentRate) {
                    $value->ChargeAmount = round($this->applyAdjustment($chargeAmount, $adjustmentRate), 2);
                }
                $value->Currency = $request->currency;
            }
        }
        $user = auth()->user();

        $bookingData = [
            'agent_id' => $agentId,
            'user_id' => $agentId,
            'booking_date' => now()->format('Y-m-d H:i:s'),
            'amount' => $preBooking->PreBookingDetails->BookingAfterPrice,
            'currency' => $booking_currency,
            'service_date' => $request->input('check_in'),
            'booking_type' => 'hotel',
            'booking_status' => $booking_status,
            'payment_type' => ''
        ];

        try {
            DB::beginTransaction();

            $booking = Booking::create($bookingData);
            $hotelBooking = HotelBooking::create($hotelBookingData);
            $this->saveHotelRoomDetails($rooms, $hotelBooking->id);

            $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin');

            if ($isCreatedByAdmin) {
                $booking->update(['created_by_admin' => true, 'booking_type_id' => $hotelBooking->id]);
                $hotelBooking->update(['created_by_admin' => true, 'approved' => true, 'booking_id' => $booking->id]);
            } else {
                $booking->update(['booking_type_id' => $hotelBooking->id]);
                $hotelBooking->update(['booking_id' => $booking->id]);
            }

            DB::commit();
            // $is_updated = null;
            // // Prepare data for PDF
            // $emailData = $this->prepareBookingData($request, $hotelBooking, $is_updated);
            // // $passenger_email = $request->input('passenger_email_address');
            // $hirerEmail = $user->email;
            // // Create and send PDF
            // $this->createBookingPDF($emailData, $hirerEmail, $request, $hotelBooking);

            return response()->json([
                'success' => true,
                'message' => 'Getting Pre-Booking Data',
                // 'redirect_route' => route('hotel.booking.view', ['id' => $hotelBooking->id]),
                'booking_id' => $booking->id,
                'hotel_booking_id' => $hotelBooking->id,
                'preBookingCancellation' => $preBooking->PreBookingRequest->PreBooking->CancellationInformations,
                'preBookingDetails' => $preBooking->PreBookingDetails,
                'bookingData' => $dataArray,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to get pre-booking data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function hotelData(mixed $request, $preBooking): array
    {
        // dd($request->all());
        return [
            'location' => ($request->location === 'N/A') ? null : $request->location,
            'check_in' => $request->check_in,
            'check_out' => $request->check_out,
            "package" => $request->package,
            "genting_rate_id" => $request->genting_rate_id,
            "total_cost" => $request->total_cost,
            "hotel_name" => $request->hotel_name,
            "currency" => $request->currency,
            "user_id" => auth()->id(),
            "booking_date" => now()->format('Y-m-d H:i:s'),
            // "room_capacity" => $request->room_capacity,
            "room_type" => $request->room_type,
            "number_of_rooms" => $request->number_of_rooms,
            "booking_type" => 'rezlive',
            "booking_key" => $preBooking->PreBookingRequest->PreBooking->RoomDetails->RoomDetail->BookingKey,
        ];
    }

    public function hotelFormValidationArray(Request $request): array
    {
        $rules = [
            "currency" => ['required'],
            "check_in" => ['required'],
            "check_out" => ['required'],
            "total_cost" => ['required'],
        ];
    
        $messages = [];
        $passengers = $request->input('passengers', []);
    
        $fullNames = [];
        $duplicates = [];
    
        foreach ($passengers as $roomIndex => $roomPassengers) {
            foreach ($roomPassengers as $passengerIndex => $passenger) {
                $firstName = strtolower(trim($passenger['first_name'] ?? ''));
                $lastName = strtolower(trim($passenger['last_name'] ?? ''));
    
                // Require nationality for first passenger of each room
                if ($passengerIndex === 0) {
                    $rules["passengers.$roomIndex.$passengerIndex.nationality_id"] = ['required'];
                    $messages["passengers.$roomIndex.$passengerIndex.nationality_id.required"] =
                        "Nationality is missing for the first traveller of room " . ($roomIndex + 1) . ".";
                }
    
                // Track full name for uniqueness check
                $fullNameKey = "$firstName|$lastName";
                if (isset($fullNames[$fullNameKey])) {
                    $duplicates[] = [$roomIndex, $passengerIndex];
                } else {
                    $fullNames[$fullNameKey] = true;
                }
            }
        }
    
        // Add custom errors for any duplicates
        foreach ($duplicates as [$roomIdx, $passengerIdx]) {
            $rules["passengers.$roomIdx.$passengerIdx.first_name"] = ['required'];
            $rules["passengers.$roomIdx.$passengerIdx.last_name"] = ['required'];
            $messages["passengers.$roomIdx.$passengerIdx.first_name.required"] = "Duplicate first and last name found in room " . ($roomIdx + 1) . ".";
            $messages["passengers.$roomIdx.$passengerIdx.last_name.required"] = "Duplicate first and last name found in room " . ($roomIdx + 1) . ".";
        }
    
        return ['rules' => $rules, 'messages' => $messages];
    }
    

    public function saveHotelRoomDetails(array $rooms, $bookingID)
    {
        foreach ($rooms as $room) {
            $firstNationalityId = null;
            $passengers = $room['passengers'];
            $childAges = $room['child_ages'] ?? [];
            $childCount = (int) $room['child_capacity'];
            $adultCount = (int) $room['adult_capacity'];
            $bed = $room['bed'] ?? false;

            $roomDetail = HotelRoomDetail::create([
                'room_no' => $room['room_number'],
                'booking_id' => $bookingID,
                'number_of_adults' => $adultCount,
                'number_of_children' => $childCount,
                'child_ages' => !empty($childAges) ? json_encode($childAges) : null,
                'extra_bed_for_child' => (bool) $bed,
            ]);

            foreach ($passengers as $index => $passenger) {
                // Use nationality from first adult if others don't have it
                if ($index === 0 && !empty($passenger['nationality_id'])) {
                    $firstNationalityId = $passenger['nationality_id'];
                }

                if (empty($passenger['nationality_id']) && $firstNationalityId !== null) {
                    $passenger['nationality_id'] = $firstNationalityId;
                }

                // Save passenger details
                HotelRoomPassengerDetail::create([
                    'room_detail_id' => $roomDetail->id,
                    'passenger_title' => $passenger['title'] ?? null,
                    'passenger_first_name' => $passenger['first_name'],
                    'passenger_last_name' => $passenger['last_name'] ?? null,
                    'phone_code' => $passenger['phone_code'] ?? null,
                    'passenger_contact_number' => $passenger['contact_number'] ?? null,
                    'passenger_email_address' => $passenger['email_address'] ?? null,
                    'nationality_id' => $passenger['nationality_id'] ?? null,
                ]);
            }
        }
    }

    public function adjustHotel($rates, array $parameters , $type = null)
    {  
        // dd($rates, $parameters);
        $agentCode = auth()->user()->agent_code;   
        $agentId = User::where('agent_code', $agentCode)
            ->where('type', 'agent')
            ->value('id');
        $adjustmentRates = AgentPricingAdjustment::getCurrentAdjustmentRate($agentId, 'hotel');
        if($type == 'hotelView'){
                
            // Loop through all adjustment rates
            foreach ($adjustmentRates as $adjustmentRate) {
                
                $rates = round($this->applyAdjustment($rates, $adjustmentRate), 2);

            }
                
        }
        else{

            $rates = $rates->toArray();
            foreach ($rates as $key => $rate) {
                           
                $rate['ConvertedPrice'] = $this->applyCurrencyConversion($rate['Price'], $parameters['current_currency'], $parameters['currency']);
                // Loop through all adjustment rates
                foreach ($adjustmentRates as $adjustmentRate) {
                        $rate['ConvertedPrice'] = round($this->applyAdjustment($rate['ConvertedPrice'], $adjustmentRate), 2);
                }
                $rates[$key]['ConvertedPrice'] = $rate['ConvertedPrice'];

            }

        }
        
        return $rates;
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
    public function prepareBookingData(Request $request, $hotelBooking, $is_updated = 0)
    {
        $roomDetails = HotelRoomDetail::where('booking_id', $hotelBooking->id)->get();
        $extra_bed_for_child = '';
        // Loop through the collection and check if any room has an extra bed for children
        foreach ($roomDetails as $room) {
            if ($room->extra_bed_for_child == 1) {
                $extra_bed_for_child = 'Yes';
                break;  // Exit the loop once we find a match
            }
        }
        $user = User::where('id', $hotelBooking->user_id)->first() ?? auth()->user();

        // Retrieve admin and agent logos from the Company table
        $adminLogo = public_path('/img/logo.png');

        // First get the agent_code of the current user
        $agentCode = $user->agent_code;

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

        $basePrice = ($request->input('currency') ?? $hotelBooking->currency) . ' ' . $hotelBooking->total_cost;

        $phone = $agent->phone ?? $user->phone;
        $phoneCode = $agent->phone_code ?? $user->phone_code;
        $hirerEmail = $agent->email ?? $user->email;
        $hirerName = ($agent ?? $user)->first_name . ' ' . ($agent ?? $user)->last_name;
        $booking_status = Booking::where('id', $hotelBooking->booking_id)->first();
        $hirerPhone = $phoneCode . $phone;
        // Assign to/from locations based on these values
        $location = Location::where(
            'id',
            $hotelBooking->location
        )->value('name');

        $bookingDate = convertToUserTimeZone($hotelBooking->created_at, 'F j, Y g:i A') ?? convertToUserTimeZone($request->input('booking_date'), 'F j, Y g:i A');
        $pickupAddress = $hotelBooking->pickup_address ?? $request->input('pickup_address');
        $dropoffAddress = $hotelBooking->dropoff_address ?? $request->input('dropoff_address');
        $child = $request->input('number_of_children') ?? $hotelBooking->number_of_children;
        $adults = $request->input('number_of_adults') ?? $hotelBooking->number_of_adults;
        $infants = $request->input('number_of_infants') ?? $hotelBooking->number_of_infants;
        $roomDetails->transform(function ($room, $key) {
            $country = Country::find($room->nationality_id);
            $room->nationality = $country ? $country->name : 'None';

            return $room;
        });
        // Group by room number
        $groupedByRoom = $roomDetails->groupBy('room_no');
        $general_remarks = $hotelBooking->general_remarks ?? $request->input('general_remarks');
        // $entitlements = json_decode($hotelBooking->gentingRate->entitlements, true);
        // $entitlements = array_slice($entitlements, 1); // removes the first item
        
        return [
            'id' => $hotelBooking->id,
            'booking_id' => $booking_status->booking_unique_id,
            'locationName' => $hotelBooking->location,
            'passenger_first_name' => $request->input('passenger_first_name') ?? $hotelBooking->passenger_first_name,
            'passenger_last_name' => $request->input('passenger_last_name') ?? $hotelBooking->passenger_last_name,
            'passenger_email_address' => $request->input('passenger_email_address') ?? $hotelBooking->passenger_email_address,
            'booking_date' => $bookingDate,
            'base_price' => $basePrice,
            'hours' => $hotelBooking->hours ?? $request->input('hours'),
            'hotel_name' => $request->input('hotel_name') ?? $hotelBooking->hotel_name,
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
            'booking_status' => $booking_status->booking_status,
            'deadlineDate' => Carbon::parse($booking_status->deadline_date)->format('F j, Y g:i A'),
            'infants' => $infants,
            'child' => $child,
            'adults' => $adults,
            'roomDetails' => $groupedByRoom,
            'room_capacity' => $request->input('room_capacity') ?? $hotelBooking->room_capacity,
            'agentLogoUrl' => $agentLogoUrl,
            'child_ages' => json_decode($request->input('child_ages')) ?? json_decode($hotelBooking->child_ages),
            'is_updated' => $is_updated,
            'created_by_admin' => $hotelBooking->created_by_admin ?? false,
            'updated_at' => convertToUserTimeZone($request->input('updated_at'), 'F j, Y g:i A') ?? convertToUserTimeZone($hotelBooking->updated_at, 'F j, Y g:i A'),
            'type' => $hotelBooking->type ?? $request->input('type'),
            'package' => $hotelBooking->package ?? $request->input('package'),
            'check_out' => $hotelBooking->check_out ?? $request->input('check_out'),
            'check_in' => $hotelBooking->check_in ?? $request->input('check_in'),
            'room_type' => $hotelBooking->room_type ?? $request->input('room_type'),
            'number_of_rooms' => $hotelBooking->number_of_rooms ?? $request->input('number_of_rooms'),
            // 'entitlements' => $entitlements,
            'extra_bed_for_child' => $extra_bed_for_child,
            'reservation_id' => $hotelBooking->reservation_id ?? null,
            'confirmation_id' => $hotelBooking->confirmation_id ?? null,
            'general_remarks' => $general_remarks,
        ];
    }

    public function createBookingPDF($bookingData, $email, Request $request, $hotelBooking)
    {
        $user = auth()->user();
        HotelBookingPDFJob::dispatch($bookingData, $email, $hotelBooking, $user);
        return true;
        $passengerName = $user->first_name . ' ' . $user->last_name;

        $directoryPath = public_path("bookings");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015
        $id = $hotelBooking->id;
        $pdfFilePathVoucher = "{$directoryPath}/hotel_booking_voucher_{$timestamp}_{$id}.pdf";
        $pdfFilePathInvoice = "{$directoryPath}/hotel_booking_invoice_{$timestamp}_{$id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/hotel_booking_admin_voucher_{$timestamp}_{$id}.pdf";

        // Load the view and save the PDF
        $pdf = Pdf::loadView('email.hotel.hotel_booking_voucher', $bookingData);
        $pdf->save($pdfFilePathVoucher);
        // booking_voucher
        $pdf = Pdf::loadView('email.hotel.hotel_booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);
        //voucher to admin
        $pdf = Pdf::loadView('email.hotel.hotel_booking_admin_voucher', $bookingData);
        $pdf->save($pdfFilePathAdminVoucher);
        $cc = ['tours@grtravel.net', 'info@grtravel.net'];

        $mailInstance = new HotelBookingVoucherMail($bookingData, $pdfFilePathInvoice, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);
    }

    public function sendVoucherEmail($request, $hotelBooking, $is_updated = 0)
    {
        $bookingData = $this->prepareBookingData($request, $hotelBooking, $is_updated);
        $user = User::where('id', $hotelBooking->user_id)->first();
        $passengerName = $user->first_name . ' ' . $user->last_name;
        $directoryPath = public_path("bookings/hotel");
        // Create the directory if it doesn't exist
        if (!file_exists($directoryPath)) {
            mkdir($directoryPath, 0755, true); // Create the directory with permissions
        }
        // Create a unique name for the PDF using bookingId and current timestamp
        $timestamp = now()->format('Ymd'); // e.g., 20241023_153015
        if ($hotelBooking->booking->booking_status === 'vouchered') {
            $pdfFilePathInvoice = "{$directoryPath}/hotel_invoice_paid_{$timestamp}_{$hotelBooking->booking_id}.pdf";
        } else {
            $pdfFilePathInvoice = "{$directoryPath}/hotel_booking_invoice_{$timestamp}_{$hotelBooking->booking_id}.pdf";
        }
        $pdfFilePathVoucher = "{$directoryPath}/hotel_booking_voucher_{$timestamp}_{$hotelBooking->booking_id}.pdf";
        $pdfFilePathAdminVoucher = "{$directoryPath}/hotel_booking_admin_voucher_{$timestamp}_{$hotelBooking->booking_id}.pdf";
        // booking_voucher
        $pdf = Pdf::loadView('email.hotel.hotel_booking_invoice', $bookingData);
        $pdf->save($pdfFilePathInvoice);

        $pdfVoucher = Pdf::loadView('email.hotel.hotel_booking_voucher', $bookingData);
        $pdfVoucher->save($pdfFilePathVoucher);

        $pdfAdmin = Pdf::loadView('email.hotel.hotel_booking_admin_voucher', $bookingData);
        $pdfAdmin->save($pdfFilePathAdminVoucher);

        $email = $user->email;

        // $isCreatedByAdmin = $tourBooking->created_by_admin; // to track creation by admin

        // Mail::to($email)->send(new TransferBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName));
        $mailInstance = new HotelBookingInvoiceMail($bookingData, $pdfFilePathInvoice, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);

        // Mail::to($email)->send(new BookingMail($bookingData, $pdfFilePathVoucher, $passengerName));
        $mailInstance = new HotelBookingVoucherMail($bookingData, $pdfFilePathVoucher, $passengerName);
        SendEmailJob::dispatch($email, $mailInstance);
        if (auth()->user()->type !== 'admin') {
            // Mail::to(['tours@grtravel.net', 'info@grtravel.net'])->send(new BookingMailToAdmin($bookingData, $pdfFilePathAdminVoucher, $passengerName));
            $emailAdmin1 = 'tours@grtravel.net';
            $emailAdmin2 = 'info@grtravel.net';
            $mailInstance = new HotelVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin1, $mailInstance);
            $mailInstance = new HotelVoucherToAdminMail($bookingData, $pdfFilePathAdminVoucher, $passengerName);
            SendEmailJob::dispatch($emailAdmin2, $mailInstance);
        }
    }

    public function printVoucher($id)
    {
        // Retrieve the booking by its ID
        $hotelBooking = HotelBooking::find($id);
        $booking = Booking::where('id', $hotelBooking->booking_id)->first();
        if (!$hotelBooking) {
            // Return a JSON response with an error message if booking is not found
            return response()->json(['error' => 'Voucher not found.'], Response::HTTP_NOT_FOUND);
        }
        // Get the created_at date and format it as Ymd (e.g., 20241030)
        $createdDate = $hotelBooking->updated_at->format('Ymd');

        $fileName = 'hotel_booking_voucher_' . $createdDate . '_' . $booking->id . '.pdf';
        $filePath = public_path('bookings/hotel/' . $fileName);

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return response()->json(['error' => 'Voucher file not found.'], Response::HTTP_NOT_FOUND);
    }

    public function printInvoice($id)
    {
        // Retrieve the booking by its ID
        $hotelBooking = HotelBooking::find($id);
        $booking = Booking::where('id', $hotelBooking->booking_id)->first();
        if (!$hotelBooking) {
            // Return a JSON response with an error message if booking is not found
            return response()->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        // Get the created_at date and format it as Ymd (e.g., 20241030)
        $createdDate = $hotelBooking->updated_at->format('Ymd');

        if ($booking->booking_status === 'vouchered') {
            $fileName = 'hotel_invoice_paid_' . $createdDate . '_' . $booking->id . '.pdf';
            $filePath = public_path('bookings/hotel/' . $fileName);
        } else {
            $fileName = 'hotel_booking_invoice_' . $createdDate . '_' . $booking->id . '.pdf';
            $filePath = public_path('bookings/hotel/' . $fileName);
        }

        if (file_exists($filePath)) {
            return response()->file($filePath);
        }

        return response()->json(['error' => 'Invoice file not found in the directory.'], Response::HTTP_NOT_FOUND);
    }

    public function HotelBookingConfirmation(){
        $bookings = HotelBooking::with('confirmation')->where('booking_type', 'rezlive')
            ->whereHas('confirmation', function ($query) {
                $query->where('confirmation_status', 'pending');
            })
            ->get();
            foreach ($bookings as  $booking) {
                try {
                    // echo "<pre>";print_r($booking['booking_id']);die();
                    $client = new Client();

                    $response = $client->post('http://test.xmlhub.com/testpanel.php/action/getConfirmationDetails', [
                        'headers' => [
                            'x-api-key' => 'c88e0664949e1ec36a2b39bc01fb7e69',
                            'Accept' => 'application/xml',
                            'Content-Type' => 'application/x-www-form-urlencoded',
                        ],
                        'form_params' => [
                            'XML' => $this->HotelConfirmationRequestXml([
                                'agent_code' => 'CD33410',
                                'user_name' => 'Ibrahimaftab',
                                'booking_id' => $booking['rezlive_booking_id'],
                                'booking_code' => $booking['rezlive_booking_code']
                            ]),
                        ]
                    ]);

                    $rawResponse = $response->getBody()->getContents();
                    $cleanedResponse = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;)/', '&amp;', $rawResponse);
                    // dd($cleanedResponse);
                    if (stripos(trim($rawResponse), '<') !== 0) {
                        return [
                            'status' => false,
                            'error' => 'Invalid response from API'
                        ];
                    }
                    
                    $xml = simplexml_load_string($cleanedResponse, "SimpleXMLElement", LIBXML_NOCDATA);
                    if($xml && isset($xml->ConfirmationDetails)){
                        $details = $xml->ConfirmationDetails;
                        if (strtolower(trim((string)$details->ConfirmationStatus)) != 'pending') {
                            // echo "<pre>";print_r(trim((string)$details->ConfirmationStatus));die();
                            $booking->confirmation()->updateOrCreate([], // No condition needed because this is a `hasOne` and Laravel knows to use `booking_id`
                                [
                                    'confirmation_status' => trim((string) $details->ConfirmationStatus),
                                    'confirmation_no'     => trim((string) $details->HotelConfirmationNo),
                                    'confirmation_note'   => trim((string) $details->ConfirmationNote),
                                    'telephone_no'        => trim((string) $details->HotelTelephoneNo),
                                    'staff_name'          => trim((string) $details->HotelStaffName),
                                ]);
                        }

                    }
                } catch (\Exception $e) {
                    return [
                        'status' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }
            // echo "<pre>";print_r($bookings->toArray());die();

    }
}
