<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\MeetingPoint;
use App\Models\Country;
use App\Models\User;
use App\Models\Location;
use App\Tables\LocationTableConfigurator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use ProtoneMedia\Splade\Facades\Splade;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Facades\Http;

class LocationController extends Controller
{
    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');

        return view('location.create', [
            'countries' => $countries,
            'location' => [],
        ]);
    }

    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */

    public function index()
    {

        $locationMeetupLists = MeetingPoint::with(['location'])->where('active', 1)->get();
        $locationMeetupLists->transform(function ($meetingPoint) {
            $meetingPoint->meeting_point_attachments = json_decode($meetingPoint->meeting_point_attachments, true);
            return $meetingPoint;
        });
        return view('location.index', [
            'location' => new LocationTableConfigurator(),
            'locationMeetupLists' => $locationMeetupLists
        ]);
    }


    public function listLocation(Request $request)
    {
        $locations = Location::all(['id', 'name']);
        return response()->json($locations);
    }

    public function store()
    {
        $request = request();
        // dd($request);
        $request->validate($this->locationFormValidateArray());
        Location::firstOrCreate($this->locationData($request));
        Splade::toast('Location Created successfully!')->success();
        return Redirect::route('location.index')->with('status', 'location-created');
    }

    public function show(User $user)
    {
        $user->toArray();
        exit;
        //return view('job.job', ['job' => $job]);
    }

    public function edit($id)
    {
        $location = Location::where('id', $id)->first();
        $location_meta = json_decode($location->location_meta, true);
        $location->area_type = $location_meta['area_type'] ?? [];
        $location->terminal_count = $location_meta['terminal_count'] ?? '';

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        return view('location.edit', ['location' => $location, 'countries' => $countries,]);

    }

    public function update($location)
    {
        $location = Location::find($location);
        $request = request();
        $request->validate($this->locationFormValidateArray($location->id));
        $location->update($this->locationData($request));
        Splade::toast('Location updated successfully!')->success();
        return Redirect::route('location.index')->with('status', 'location-created');
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
    public function locationFormValidateArray($id = null): array
    {
        $basicValidate = [
            "name" => ['required', 'string', 'max:255'],
            "city_id" => ['required'],
            "country_id" => ['required'],
            "latitude" => ['required', 'numeric'],
            "longitude" => ['required', 'numeric'],
        ];
    
        return $basicValidate;
    }
    

    /**
     * @param mixed $request
     * @return array
     */
    public function locationData(mixed $request): array
    {

        $locationMeta = [];

        if ($request->location_type == 'airport') {
            $locationMeta['terminal_count'] = $request->has('terminal_count') ? $request->input('terminal_count') : '';
            $locationMeta['area_type'] = $request->has('area_type') ? $request->area_type : [];

        }

        return [
            'name' => $request->name,
            "city_id" => $request->city_id,
            "country_id" => $request->country_id,
            "latitude" => $request->latitude,
            "longitude" => $request->longitude,
            "user_id" => auth()->id(),
            'location_meta' => json_encode($locationMeta),
            'location_type' => $request->location_type,
        ];
    }


    public function searchLocation()
    {
        $request = request();
        $search = $request->get('search');
        // Query users based on the search input
        $locations = Location::where('name', 'like', '%' . $search . '%')
            ->select('id', 'name', 'name')
            ->limit(10)
            ->get();

        // Return the response in the format Splade expects
        return response()->json($locations->map(function ($location) {
            return [
                'id' => $location->id,
                'label' => $location->name
            ];
        }));
    }

}
