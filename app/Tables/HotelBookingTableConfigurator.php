<?php

namespace App\Tables;


use App\Models\GentingBooking;
use App\Models\HotelBooking;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class HotelBookingTableConfigurator extends AbstractTable
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
                        ->orWhere('hotel_bookings.booking_id', 'LIKE', "%{$value}%")
                        ->orWhere('hotel_bookings.total_cost', 'LIKE', "%{$value}%")
                        ->orWhere('location', 'LIKE', "%{$value}%")
                        ->orWhere('hotel_bookings.hotel_name', 'LIKE', "%{$value}%")
                        ->orWhere('hotel_bookings.currency', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_unique_id', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_type', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_status', 'LIKE', "%{$value}%")
                        ->orWhere('user.agent_code', 'LIKE', "%{$value}%")
                        ->orWhere('user.email', 'LIKE', "%{$value}%");
                });
            });
        });

        $user = auth()->user();

        $query = HotelBooking::query()
            ->leftJoin('bookings', 'bookings.id', '=', 'hotel_bookings.booking_id') 
            ->leftJoin('users as user', 'hotel_bookings.user_id', '=', 'user.id') 
            ->when($user->type === 'agent', function ($query) use ($user) {
                $staffIds = $user->staff()->pluck('id');
                $query->whereIn('hotel_bookings.user_id', $staffIds->push($user->id));
            })->orderBy('bookings.booking_date', 'desc');

        return QueryBuilder::for($query)
            ->allowedFilters([
                'hotel_name',
                'package',
                'check_in',
                'check_out',
                'booking_id',
                'users.agent_code',
                'users.email',
                'bookings.booking_type',
                'hotel_bookings.currency',
                'total_cost',
                AllowedFilter::exact('booking_id'),
                AllowedFilter::exact('bookings.booking_unique_id'),
                AllowedFilter::exact('booking_status', 'bookings.booking_status'),
                $globalSearch,
            ])
            ->allowedSorts(['booking_id','bookings.booking_type','bookings.booking_unique_id','bookings.booking_date','bookings.deadline_date', 'total_cost','check_in','check_out','hotel_name']);
    }

    /**
     * Configure the given SpladeTable.
     *
     * @param \ProtoneMedia\Splade\SpladeTable $table
     * @return void
     */
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
            ->column('hotel_name', label: 'Hotel Name', sortable: true, searchable: true)
            ->column('bookings.booking_type', label: 'Booking Type',sortable: true, searchable: true, hidden: true, as: function ($value, $model) {
                return $model->booking_type ?? 'N/A';
            })
            ->column('location', label: 'Location', hidden: true, as: function ($value, $model) {
                return $model->location ?? 'N/A';
            })
            ->column('check_in', label: 'CheckIn', sortable: true, searchable: false, hidden: true)
            ->column('check_out', label: 'CheckOut', sortable: true, searchable: false, hidden: true)
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
            ->column('booking_status', label: 'Booking Status', sortable: false, searchable: true,
    as: function ($value, $model) {
        return $value === 'confirmed' ? 'Confirmed/Unpaid' : ucfirst($value ?? 'N/A');
    }
)
            ->column('actions', label: 'Actions', as: function ($value, $model) {
                // $action = route('tour_booking.edit', $model->id);
                // $slot = 'Update';
                // return view('table.component.actions', compact('model', 'action', 'slot'));
                $approveForm = '';
                $editButton = ''; 
                $chatButton = '';

                if($model->booking_id != null){

                    // Show Approve button only if sent_approval is true
                    if ($model->sent_approval) {
                        $approveForm = '<a href="' . route('auth.hotelBooking_details', $model->booking_id) . '" class="btn bg-transparent px-2 mt-1 border-none">
                
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
                    $editButton = '<a href="' . route('auth.hotelBooking_details', $model->booking_id) . '" class="btn bg-transparent px-2 mt-1 border-none">
                        <svg data-tooltip-target="tooltip-view" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye w-6 h-6 text-gray-500" viewBox="0 0 16 16">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zm-8 4a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-1.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/>
                        </svg>
                    </a>
                    <div id="tooltip-view" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700" style="border-radius:10px;">
                        View Details
                        <div class="tooltip-arrow" data-popper-arrow></div>
                    </div>';

                    $chatButton = '<a href="' . route('auth.chat.booking', $model->booking_id) . '" class="btn bg-transparent px-2 mt-1 border-none">
                        <svg class="bi bi-eye w-6 h-6 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"width="16" height="16" fill="none" viewBox="0 0 24 24">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 10.5h.01m-4.01 0h.01M8 10.5h.01M5 5h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-6.6a1 1 0 0 0-.69.275l-2.866 2.723A.5.5 0 0 1 8 18.635V17a1 1 0 0 0-1-1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/>
                        </svg>

                        </a>
                        <div id="tooltip-view" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700" style="border-radius:10px;">
                            Chat with Agent
                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>';
                }


                    // Return Approve button (if applicable) and Edit button
                    return new HtmlString($approveForm . $editButton . $chatButton);
            })
            ->paginate(15)
            ->bulkAction(
                label: 'Delete Selected',
                each: fn(HotelBooking $hotel) => $hotel->delete(),
                confirm: 'Are you sure you want to delete selected bookings?',
                confirmButton: 'Yes, Delete',
                cancelButton: 'Cancel',
            )
            ->export();
    }

}