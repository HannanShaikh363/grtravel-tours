<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AgentPricingAdjustment;
use App\Models\Location;
use App\Models\MeetingPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use ProtoneMedia\Splade\Facades\Toast;

class MeetingPointController extends Controller
{
    //

    public function create(Request $request)
    {
        $request->validate([
            'location' => ['required', 'array'], // Ensure 'location' is an array
            'location.id' => ['required', 'integer'],
            'location.name' => ['required', 'string'], // Validate 'name' within the 'location'
            'meeting_point_desc' => ['required', 'string'],
            'terminal' => ['required', 'integer'],
            'airport_areas' => ['required', 'string'],
            'meeting_point_name' => [
                'required',
                'string',
                Rule::unique('meeting_point')->where(function ($query) use ($request) {
                    return $query->where('location_id', $request->location['id'])
                        ->where('terminal', $request->terminal)
                        ->where('airport_areas', $request->airport_areas)
                        ->where('active', 1);
                })
            ],
            'meeting_point_type' => ['required', 'string', 'in:airport,hotel,city,other'],
        ], [
            'location.array' => 'The agent field should be selected via autocomplete.',  // Custom message for 'array'
            'airport_areas.required' => 'Airport areas and terminal combination already exists.',
        ]);

        // Custom validation rule to check the combination of airport_areas and terminal
        $request->validate([
            'airport_areas' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    // Check if the combination of airport_areas and terminal already exists
                    $existingMeetingPoint = MeetingPoint::where('airport_areas', $value)
                        ->where('terminal', $request->terminal)
                        ->where('location_id', $request->location['id'])
                        ->where('active', 1)
                        ->exists();

                    if ($existingMeetingPoint) {
                        $fail('The combination of airport area and terminal already exists.');
                    }
                }
            ],
        ]);

        $attachmentUrls = $this->uploadLogo($request);
        $airportMeetingPoint = new MeetingPoint();
        $airportMeetingPoint->location_id = $request->location['id'];
        $airportMeetingPoint->meeting_point_desc = $request->meeting_point_desc;
        $airportMeetingPoint->meeting_point_name = $request->meeting_point_name;
        $airportMeetingPoint->user_id = Auth::user()->id;
        $airportMeetingPoint->active = 1;
        $airportMeetingPoint->meeting_point_attachments = json_encode($attachmentUrls);
        $airportMeetingPoint->terminal = $request->terminal;
        $airportMeetingPoint->airport_areas = $request->airport_areas;
        $airportMeetingPoint->meeting_point_type = $request->meeting_point_type;
        $airportMeetingPoint->save();
        $location = Location::find($request->location['id']);
        $location->location_type = $request->meeting_point_type;
        $location->save();
        Toast::title('Airport Meetup Point successfully!')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
        return redirect()->back()->with('success', 'Airport Meetup Point successfully');
    }

    public function store($id, Request $request)
    {

        $meetingPoint = MeetingPoint::findOrFail($id);
        $meetingPoint->active = is_null($request->active) ? 0 : 1;
        $meetingPoint->save();
        return redirect()->back()->with('success', 'Meeting Point updated successfully');
    }

    public function update(Request $request, $id)
    {
        $meetingPoint = MeetingPoint::findOrFail($id);
    
        // Validate input
        $validatedData = $request->validate([
            'location' => ['required', 'array'], // Ensure 'location' is an array
            'location.id' => ['required', 'integer'],
            'location.name' => ['required', 'string'], // Validate 'name' within 'location'
            'meeting_point_name' => 'required|string|max:255',
            'meeting_point_type' => 'required|string',
            'terminal' => 'nullable|string',
            'airport_areas' => 'nullable|string',
            'meeting_point_desc' => 'required|string',
            'meeting_point_attachments.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);
    
        // Update meeting point details
        $meetingPoint->update([
            'location_id' => $validatedData['location']['id'], // Correctly access 'id' within 'location'
            'meeting_point_name' => $validatedData['meeting_point_name'],
            'meeting_point_type' => $validatedData['meeting_point_type'],
            'terminal' => $validatedData['terminal'] ?? null,
            'airport_areas' => $validatedData['airport_areas'] ?? null,
            'meeting_point_desc' => $validatedData['meeting_point_desc'],
        ]);
    
        // Check if there are any existing images to delete
        $existingImages = json_decode($meetingPoint->meeting_point_attachments, true) ?? [];
    
        // If new images are uploaded
        $logoUrls = $this->uploadLogo($request);
    
        if (!empty($logoUrls)) {
            // If images exist, delete the old ones
            if (!empty($existingImages)) {
                foreach ($existingImages as $image) {
                    // Delete the old image from storage
                    if (Storage::disk('public')->exists($image)) {
                        Storage::disk('public')->delete($image);
                    }
                }
            }
    
            // Update the meeting point attachments with the new images
            $meetingPoint->meeting_point_attachments = json_encode($logoUrls);
            $meetingPoint->save();
        }
    
        return redirect()->back()->with('success', 'Meeting point updated successfully.');
    }
    


    /**
     * @param mixed $request
     * @param string $logoUrl
     * @return string
     */
    public function uploadLogo(mixed $request): array
    {

        $logoUrls = [];  // Array to store the URLs of uploaded images

        if ($request->hasFile('meeting_point_attachments')) {


            // Retrieve all uploaded files
            $files = $request->file('meeting_point_attachments');
            // Define the directory path where you want to store the files
            $directory = 'uploads/files';

            foreach ($files as $file) {
                // Store each file in the directory and get the stored file path
                $path = $file->store($directory, 'public');
                // Generate the public URL for each file
                $logoUrls[] = Storage::url($path);
            }
        }

        return $logoUrls;
    }

    public function active($id)
    {
        $airportMeetingPoint = MeetingPoint::find($id);
        $airportMeetingPoint->active = 0;
        $airportMeetingPoint->save();
        Toast::success('Airport Meetup Point successfully');
        return redirect()->back()->with('success', 'Airport Meetup Point successfully');
    }
}
