<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Transport;
use App\Models\TransportDriver;
use App\Models\TransportInsurance;
use App\Models\User;
use App\Tables\TransportTableConfigurator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use ProtoneMedia\Splade\Facades\Splade;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use ProtoneMedia\Splade\Facades\Toast;

class TransportController extends Controller
{
    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('transport.create');
    }

    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('transport.index', [
            'transport' => new TransportTableConfigurator(),

        ]);
    }

    public function store()
    {
        $request = request();
        $request->validate($this->transportFormValidateArray());
        $transport = Transport::create($this->transportData($request));
        TransportDriver::create($this->transportDriverData($request, $transport->id));
        TransportInsurance::create($this->transportInsuranceData($request, $transport->id));

        Toast::title('Transport Created')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
        return Redirect::route('transport.index')->with('status', 'transport-created');
    }


    public function show(User $user)
    {
        $user->toArray();
        exit;
        //return view('job.job', ['job' => $job]);
    }

    public function edit($id)
    {

        $transport = Transport::where('id', $id)->with(['driver', 'insurance'])->first();

        return view('transport.edit', ['transport' => $transport,]);
    }

    public function update($transportId)
    {
        // Fetch the existing transport record along with driver and insurance relationships
        $transport = Transport::where('id', $transportId)->with(['driver', 'insurance'])->first();

        $request = request();

        // Validate the incoming request data
        $request->validate($this->transportFormValidateArray($transportId));

        // Update the transport record
        $transport->update($this->transportData($request));

        // Conditionally update the TransportDriver(s)
        if ($request->has('driver')) {
            foreach ($request->input('driver') as $driverData) {
                if (isset($driverData['id'])) {
                    $existingDriver = $transport->driver()->find($driverData['id']);
                    if ($existingDriver) {
                        $existingDriver->update($driverData);
                    }
                } else {
                    $transport->driver()->create(array_merge($driverData, ['transport_id' => $transport->id]));
                }
            }
        }

        // Conditionally update the transport insurance if the insurance model exists and request data has insurance
        if ($transport->insurance && $request->has('insurance')) {
            $transport->insurance->update($this->transportInsuranceData($request, $transport->id));
        }

        Toast::title('Transport Updated')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
        return Redirect::route('transport.index')->with('status', 'transport-updated');
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
    public function transportFormValidateArray($transportId = null): array
    {
        return [
            "vehicle_make" => ['required', 'string', 'max:255'],
            'vehicle_model' => [
                'required',
                'string',
                'max:255',
                Rule::unique('transports', 'vehicle_model')->ignore($transportId),
            ],
            "package" => ['required', 'string', 'max:255'],
            "vehicle_seating_capacity" => ['required', 'integer'],
            "vehicle_luggage_capacity" => ['required', 'integer'],
            "driver.driver_full_name" => ['nullable', 'string', 'max:255'],
            "driver.driver_contact_number" => ['nullable', 'string', 'max:255'],
            "driver.driver_email_address" => ['nullable', 'string', 'max:255'],
            "insurance.insurance_company_name" => ['nullable', 'string', 'max:255'],
            "insurance.insurance_policy_number" => ['nullable', 'string', 'max:255'],
            "insurance.insurance_expiry_date" => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param mixed $request
     * @return array
     */
    public function transportData(mixed $request): array
    {
        return [
            "vehicle_make" => $request->vehicle_make,
            "vehicle_model" => $request->vehicle_model,
            "package" => $request->package,
            "vehicle_year_of_manufacture" => $request->vehicle_year_of_manufacture,
            "vehicle_vin" => $request->vehicle_vin,
            "vehicle_registration_number" => $request->vehicle_registration_number,
            "vehicle_license_plate_number" => $request->vehicle_license_plate_number,
            "vehicle_color" => $request->vehicle_color,
            "vehicle_engine_number" => $request->vehicle_engine_number,
            "vehicle_seating_capacity" => $request->vehicle_seating_capacity,
            "vehicle_luggage_capacity" => $request->vehicle_luggage_capacity,
            "vehicle_transmission_type" => $request->vehicle_transmission_type,
            "vehicle_fuel_type" => $request->vehicle_fuel_type,
            "vehicle_body_type" => $request->vehicle_body_type,
            "user_id" => auth()->id(),
        ];
    }

    public function transportDriverData($request, $transporid)
    {
        return [
            "driver_full_name" => $request->driver['driver_full_name'] ?? null,
            "driver_contact_number" => $request->driver['driver_contact_number'] ?? null,
            "driver_email_address" => $request->driver['driver_email_address'] ?? null,
            "transport_id" => $transporid
        ];
    }


    public function transportInsuranceData($request, $transporid)
    {
        return [
            "insurance_company_name" => $request->insurance['insurance_company_name'] ?? null,
            "insurance_policy_number" => $request->insurance['insurance_policy_number'] ?? null,
            "insurance_expiry_date" => $request->insurance['insurance_expiry_date'] ?? null,
            "transport_id" => $transporid
        ];
    }


    public function search(Request $request)
    {

        $search = $request->get('search');
        $query = Transport::where('vehicle_make', 'LIKE', '%' . $search . '%')->orWhere('vehicle_model', 'LIKE', '%' . $search . '%')
            ->select(['transports.id', DB::raw("concat(vehicle_make,' ', vehicle_model) as name")]);
        $results = $query->get()->toArray();

        return response()->json(['items' => $results]);
    }

    public function listTransport(Request $request)
    {
        // Fetch all transports with vehicle_model, seating_capacity, and luggage_capacity
        $transports = Transport::all(['id', 'vehicle_model', 'vehicle_seating_capacity', 'vehicle_luggage_capacity'])->map(function ($transport) {
            // Initialize the vehicle model string
            $vehicleModelString = $transport->vehicle_model;

            // Check if seating capacity and luggage capacity exist, and append them to the vehicle model string
            $additionalInfo = [];

            if ($transport->vehicle_seating_capacity) {
                $additionalInfo[] = 'Seats: ' . $transport->vehicle_seating_capacity;
            }

            if ($transport->vehicle_luggage_capacity) {
                $additionalInfo[] = 'Luggage: ' . $transport->vehicle_luggage_capacity;
            }

            // Join the additional information if it exists
            if (!empty($additionalInfo)) {
                $vehicleModelString .= ' (' . implode(', ', $additionalInfo) . ')';
            }

            return [
                'id' => $transport->id,
                'vehicle_model' => $vehicleModelString,
            ];
        });

        // Add a default empty value to the list
        $defaultEmptyValue = collect([
            ['id' => '', 'vehicle_model' => 'Select a transport']
        ]);

        // Merge the default empty value with the list of transports
        $result = $defaultEmptyValue->concat($transports);

        return response()->json($result);
    }



    public function showCapacity($id)
    {

        // Fetch the transport by ID
        $transport = Transport::find($id);

        if ($transport) {
            // Return the vehicle seating capacity and any other needed data
            return response()->json([
                'vehicle_luggage_capacity' => $transport->vehicle_luggage_capacity,
                'vehicle_seating_capacity' => $transport->vehicle_seating_capacity,
            ]);
        }

        // Return a 404 response if the transport is not found
        return response()->json(['message' => 'Transport not found'], 404);
    }
}
