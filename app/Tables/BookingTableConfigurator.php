<?php

namespace App\Tables;

use App\Models\FleetBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class BookingTableConfigurator extends AbstractTable
{
    /**
     * Create a new instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Determine if the user is authorized to perform bulk actions and exports.
     *
     * @return bool
     */
    public function authorize(Request $request)
    {
        return true;
    }

    /**
     * The resource or query builder.
     *
     * @return mixed
     */
    public function for()
    {
        $globalSearch = AllowedFilter::callback('global', function ($query, $value) {
            $query->where(function ($query) use ($value) {
                Collection::wrap($value)->each(function ($value) use ($query) {
                    $query
                        ->orwhere('passenger_full_name', 'LIKE', "%{$value}%")
                        ->orwhere('bookings.booking_unique_id', 'LIKE', "%{$value}%")
                        ->orWhere('passenger_email_address', 'LIKE', "%{$value}%")
                        ->orWhere('fromLocation.name', 'LIKE', "%{$value}%")
                        ->orWhere('toLocation.name', 'LIKE', "%{$value}%")
                        ->orWhere('booking_date', 'LIKE', "%{$value}%")
                        ->orWhere('pick_date', 'LIKE', "%{$value}%")
                        ->orWhere('flight_arrival_time', 'LIKE', "%{$value}%")
                        ->orWhere('flight_departure_time', 'LIKE', "%{$value}%")
                        ->orWhere('fleet_bookings.currency', 'LIKE', "%{$value}%")
                        ->orWhere('booking_cost', 'LIKE', "%{$value}%");
                });
            });
        });

        // Get the authenticated user
        $user = auth()->user();

        // Start building the query
        $query = FleetBooking::query()
            ->join('locations as fromLocation', 'fleet_bookings.from_location_id', '=', 'fromLocation.id')
            ->join('locations as toLocation', 'fleet_bookings.to_location_id', '=', 'toLocation.id')
            ->join('bookings', 'fleet_bookings.booking_id', '=', 'bookings.id')
            ->select('bookings.booking_unique_id as booking_uniqueId','fleet_bookings.*', 'fromLocation.name as from_location_name', 'toLocation.name as to_location_name')
            ->with('fromLocation', 'toLocation', 'transport', 'booking', 'getRate')->orderBy('bookings.booking_date', 'desc');

        // Filter based on user type
        if ($user->type === 'agent') {

            $query->where('bookings.user_id', $user->id); // Only get bookings for this agent
        }

        return QueryBuilder::for($query)
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::exact('booking_uniqueId','bookings.booking_unique_id'),
                AllowedFilter::exact('Agent'),
                AllowedFilter::exact('passenger_full_name'),
                AllowedFilter::exact('passenger_email_address'),
                AllowedFilter::exact('passenger_contact_number'),
                AllowedFilter::exact('from_location_name'),
                AllowedFilter::exact('to_location_name'),
                AllowedFilter::exact('created_at'),
                AllowedFilter::exact('pick_date'),
                AllowedFilter::exact('pick_time'),
                AllowedFilter::exact('flight_arrival_time'),
                AllowedFilter::exact('flight_departure_time'),
                AllowedFilter::exact('booking_cost'),
                AllowedFilter::exact('currency'),
                AllowedFilter::exact('status'),
                $globalSearch,
            ])
            ->allowedSorts(['id','bookings.booking_unique_id', 'Agent Info', 'passenger_full_name', 'passenger_email_address', 'from_location_name', 'to_location_name', 'created_at', 'pick_date', 'pick_time', 'booking_cost', 'currency', 'status']);
    }


    /**
     * Configure the given SpladeTable.
     *
     * @param \ProtoneMedia\Splade\SpladeTable $table
     * @return void
     */
    public function configure(SpladeTable $table)
    {
        $table
            ->withGlobalSearch()
            ->column(key: 'id', label: 'ID', searchable: true, sortable: true, hidden: true)
            ->column(key: 'booking_uniqueId', label: 'Booking Id', searchable: true, sortable: true)
            ->column(key: 'agent_info', label: 'Agent Info', as: function ($column, $model) {
                return optional($model->booking->user)->first_name . ' ' . optional($model->booking->user)->last_name . ' (' . optional($model->booking->user)->email . ')';
            })
            ->column(key: 'agent_code', label: 'Agent Code', as: function ($column, $model) {
                return optional($model->booking->user)->agent_code;
            })
            ->column(key: 'passenger_full_name', label: 'Passenger Name', searchable: true, sortable: true, hidden: true)
            ->column(key: 'passenger_email_address', label: 'Passenger Email', sortable: true, hidden: true)
            ->column(key: 'from_location_name', label: 'From', searchable: true, sortable: true, hidden: true)
            ->column(key: 'to_location_name', label: 'To', searchable: true, sortable: true, hidden: true)
            ->column(
                key: 'created_at',
                label: 'Booking Date & Time',
                searchable: true,
                sortable: true, 
                hidden: true,
                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return convertToUserTimeZone($model->created_at);
                }
            )
            ->column(key: 'pick_date', label: 'PickUp Date', sortable: true, hidden: true)
            ->column(key: 'pick_time', label: 'PickUp Time', sortable: true, hidden: true)
            ->column(key: 'flight_arrival_time', label: 'Flight Arrival Time', searchable: true, sortable: true, hidden: true)
            ->column(key: 'flight_departure_time', label: 'Flight Departure Time', searchable: true, sortable: true, hidden: true)
            ->column(key: 'currency', label: 'Currency', searchable: true, sortable: true)
            ->column(key: 'booking_cost', label: 'Booking Cost', searchable: true, sortable: true)
            // Approval Status Column
            ->column(key: 'approved', label: 'Approval Status', as: function ($column, $model) {
                // Check if 'sent_approval' is true
                if ($model->sent_approval) {
                    return 'Unapproved';
                }

                // Otherwise, check if 'approved' is true or false
                return $model->approved ? 'Approved' : 'Cancelled';
            })

            // Actions Column: Approve, Reject, Edit (Pencil Icon)
            ->column(
                key: 'actions',
                label: 'Actions',
                exportAs: false,
                as: function ($column, $model) {
                    $approveForm = '';

                    // Show Approve button only if sent_approval is true
                    if ($model->sent_approval) {
                        $approveForm = '<a href="' . route('booking.details', $model->booking_id) . '" class="inline mt-2">
                            <svg data-tooltip-target="tooltip-approve" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="green" class="bi bi-check w-6 h-6 text-green-600" viewBox="0 0 16 16">
                                <path d="M13.485 1.929a.75.75 0 0 1 1.06 1.06l-8 8a.75.75 0 0 1-1.06 0l-4-4a.75.75 0 1 1 1.06-1.06L6 9.44l7.485-7.485z"/>
                            </svg>
                        </a>
                        <div id="tooltip-approve" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                            Approve
                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>';
                    }
                    // Edit (Pencil) button is shown always
                    $editButton = '<a href="' . route('booking.details', $model->booking_id) . '" class="btn bg-transparent px-2 mt-1 border-none">
                        <svg data-tooltip-target="tooltip-view" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye w-6 h-6 text-gray-500" viewBox="0 0 16 16">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zm-8 4a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-1.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/>
                        </svg>
                    </a>
                    <div id="tooltip-view" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700" style="border-radius:10px;">
                        View Details
                        <div class="tooltip-arrow" data-popper-arrow></div>
                    </div>';

                    // Return Approve button (if applicable) and Edit button
                    return new HtmlString($approveForm . $editButton);
                }
            )


            // Pagination
            ->paginate(15)

            // Bulk Action: Delete
            ->column('id', sortable: true, hidden: true)
            ->bulkAction(
                label: 'Delete',
                each: fn(FleetBooking $bookings) => $this->deleteBooking($bookings),
                before: fn() => info('Deleting the selected Booking'),
                // after: fn() => Toast::info('Booking(s) have been deleted!'),
                confirm: 'Deleting Booking Data?',
                confirmText: 'Are you sure you want to delete the booking data?',
                confirmButton: 'Yes, Delete Selected Row(s)!',
                cancelButton: 'No, Do Not Delete!',
            )

            // Enable Export
            ->export();
    }
    public function deleteBooking(FleetBooking $bookings)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete booking')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete booking.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        $bookings->delete();

        // Show success message after deletion
        Toast::info('Booking has been deleted!')->autoDismiss(3);
    }
}
