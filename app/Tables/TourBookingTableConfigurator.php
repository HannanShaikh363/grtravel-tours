<?php

namespace App\Tables;

use App\Models\TourBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\HtmlString;

class TourBookingTableConfigurator extends AbstractTable
{
    public function __construct()
    {
        //
    }

    public function authorize(Request $request)
    {
        return true;
    }

    public function for()
    {
        $globalSearch = AllowedFilter::callback('global', function ($query, $value) {
            $query->where(function ($query) use ($value) {
                Collection::wrap($value)->each(function ($value) use ($query) {
                    $query
                        ->orWhere('tour_bookings.booking_date', 'LIKE', "%{$value}%")
                        ->orWhere('tour_bookings.booking_id', 'LIKE', "%{$value}%")
                        ->orWhere('tour_bookings.total_cost', 'LIKE', "%{$value}%")
                        ->orWhere('locations.name', 'LIKE', "%{$value}%")
                        ->orWhere('tour_bookings.tour_name', 'LIKE', "%{$value}%")
                        ->orWhere('tour_bookings.package', 'LIKE', "%{$value}%")
                        ->orWhere('tour_bookings.passenger_full_name', 'LIKE', "%{$value}%")
                        ->orWhere('tour_bookings.passenger_contact_number', 'LIKE', "%{$value}%")
                        ->orWhere('tour_bookings.currency', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_unique_id', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_type', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_status', 'LIKE', "%{$value}%")
                        ->orWhere('user.agent_code', 'LIKE', "%{$value}%")
                        ->orWhere('user.email', 'LIKE', "%{$value}%");
                });
            });
        });

        $user = auth()->user();

        $query = TourBooking::query()
            ->with(['booking', 'users', 'location'])
            ->leftJoin('bookings', 'bookings.id', '=', 'tour_bookings.booking_id') 
            ->leftJoin('locations', 'tour_bookings.location_id', '=', 'locations.id') // Replace with actual foreign key
            ->leftJoin('users as user', 'tour_bookings.user_id', '=', 'user.id') 
            ->when($user->type === 'agent', function ($query) use ($user) {
                $staffIds = $user->staff()->pluck('id');
                $query->whereIn('tour_bookings.user_id', $staffIds->push($user->id));
            })->orderBy('bookings.booking_date', 'desc');

        return QueryBuilder::for($query)
            ->allowedFilters([
                'booking_date',
                'tour_name',
                'total_cost',
                'booking_id',
                'users.agent_code',
                'users.email',
                'bookings.booking_type',
                'package',
                'passenger_full_name',
                'passenger_contact_number',
                'tour_bookings.currency',
                'total_cost',
                AllowedFilter::exact('tour_id'),
                AllowedFilter::exact('bookings.booking_unique_id'),
                AllowedFilter::exact('booking_status', 'bookings.booking_status'),
                $globalSearch,
            ])
            ->allowedSorts(['passenger_contact_number','passenger_full_name','package','bookings.booking_type','bookings.booking_unique_id','bookings.booking_date','bookings.deadline_date', 'total_cost','tour_date','tour_time','tour_name', 'flight_arrival_time']);
    }

    public function configure(SpladeTable $table)
    {

        $table->withGlobalSearch()
            ->column('booking_id', label: 'Id', sortable: true, searchable: true, hidden: true)
            ->column('bookings.booking_unique_id', label: 'Booking Id',sortable: true, searchable: true, as: function ($value, $model) {
                return $model->booking_unique_id ?? 'N/A';
            })
            ->column('users.agent_code', label: 'Agent Code', as: function ($value, $model) {
                return $model->agent_code ?? 'N/A';
            })
            ->column(key: 'agent_info', label: 'Agent Info', as: function ($column, $model) {
                return $model->first_name . ' ' . $model->last_name . ' (' . $model->email . ')';
            })
            ->column('tour_name', label: 'Tour Name', sortable: true, searchable: true, hidden: true)
            ->column('bookings.booking_type', label: 'Booking Type',sortable: true, searchable: true, hidden: true, as: function ($value, $model) {
                return $model->booking_type ?? 'N/A';
            })
            ->column('package', label: 'Package', sortable: true, searchable: true, hidden: true)
            ->column('location.name', label: 'Location', hidden: true, as: function ($value, $model) {
                return $model->location->name ?? 'N/A';
            })
            ->column('passenger_full_name', label: 'Passenger Name', sortable: true, searchable: true, hidden: true)
            ->column('passenger_contact_number', label: 'Passenger Contact Number', sortable: true, searchable: true, hidden: true)
            ->column('tour_date', label: 'Pickup Date', sortable: true, searchable: false, hidden: true)
            ->column('tour_time', label: 'Pickup Time', sortable: true, searchable: false, hidden: true)
            ->column('bookings.booking_date', label: 'Booking Date', sortable: true, searchable: false,
                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return convertToUserTimeZone($model->booking_date);
                }
            )
            ->column('bookings.deadline_date', label: 'Deadline Date', sortable: true, searchable: false, hidden: true,
                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return convertToUserTimeZone($model->deadline_date);
                }
            )
            ->column('currency', label: 'Currency', sortable: false, searchable: true)
            ->column('total_cost', label: 'Total Cost', sortable: true, searchable: true)
            ->column('booking_status', label: 'Status', sortable: false, searchable: true,
                as: function ($value, $model) {
                    return $value === 'confirmed' ? 'Confirmed/Unpaid' : ucfirst($value ?? 'N/A');
                }
            )
            ->column(key: 'created_at', label: 'Created at', searchable: false, sortable: true, canBeHidden: true, hidden: true,
                    as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return convertToUserTimeZone($model->created_at);
                }
            )
            ->column(key: 'updated_at', label: 'Updated at', searchable: false, sortable: true, canBeHidden: true, hidden: true,
                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return convertToUserTimeZone($model->updated_at);
                }
            )
            ->column('actions', label: 'Actions', as: function ($value, $model) {
                // $action = route('tour_booking.edit', $model->id);
                // $slot = 'Update';
                // return view('table.component.actions', compact('model', 'action', 'slot'));
                $approveForm = '';
                $editButton = ''; 

                if($model->booking_id != null){

                    // Show Approve button only if sent_approval is true
                    if ($model->sent_approval) {
                        $approveForm = '<a href="' . route('tour_booking.details', $model->booking_id) . '" class="inline">';
                        // $approveForm .= csrf_field();
                        $approveForm .= '<svg data-tooltip-target="tooltip-approve" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="green" class="bi bi-check w-6 h-6 text-green-600 mt-6 border-none" viewBox="0 0 16 16">
                                <path d="M13.485 1.929a.75.75 0 0 1 1.06 1.06l-8 8a.75.75 0 0 1-1.06 0l-4-4a.75.75 0 1 1 1.06-1.06L6 9.44l7.485-7.485z"/>
                            </svg>
                        <div id="tooltip-approve" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                            Approve
                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>';
                        $approveForm .= '</a>';
                    }

                    // Edit (Pencil) button is shown always
                    $editButton = '<a href="' . route('tour_booking.details', $model->booking_id) . '" class="btn bg-transparent px-2 mt-1 border-none">
                        <svg data-tooltip-target="tooltip-view" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye w-6 h-6 text-gray-500" viewBox="0 0 16 16">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zm-8 4a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-1.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/>
                        </svg>
                    </a>
                    <div id="tooltip-view" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700" style="border-radius:10px;">
                        View Details
                        <div class="tooltip-arrow" data-popper-arrow></div>
                    </div>';
                }


                    // Return Approve button (if applicable) and Edit button
                    return new HtmlString($approveForm . $editButton);
            })
            ->paginate(15)
            ->bulkAction(
                label: 'Delete Selected',
                each: fn(TourBooking $tour) => $tour->delete(),
                confirm: 'Are you sure you want to delete selected bookings?',
                confirmButton: 'Yes, Delete',
                cancelButton: 'Cancel',
            )
            ->export();
    }
}
