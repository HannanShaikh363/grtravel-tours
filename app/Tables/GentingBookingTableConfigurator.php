<?php

namespace App\Tables;


use App\Models\GentingBooking;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class GentingBookingTableConfigurator extends AbstractTable
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
                        ->orWhere('genting_bookings.booking_id', 'LIKE', "%{$value}%")
                        ->orWhere('genting_bookings.total_cost', 'LIKE', "%{$value}%")
                        ->orWhere('locations.name', 'LIKE', "%{$value}%")
                        ->orWhere('genting_bookings.hotel_name', 'LIKE', "%{$value}%")
                        ->orWhere('genting_bookings.package', 'LIKE', "%{$value}%")
                        ->orWhere('genting_bookings.currency', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_unique_id', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_type', 'LIKE', "%{$value}%")
                        ->orWhere('bookings.booking_status', 'LIKE', "%{$value}%")
                        ->orWhere('user.agent_code', 'LIKE', "%{$value}%")
                        ->orWhere('company.agent_name', 'LIKE', "%{$value}%")
                        ->orWhere('user.email', 'LIKE', "%{$value}%");
                });
            });
        });

        $user = auth()->user();

        $query = GentingBooking::query()
            ->with(['booking', 'users.company', 'location'])
            ->leftJoin('bookings', 'bookings.id', '=', 'genting_bookings.booking_id')
            ->leftJoin('locations', 'genting_bookings.location_id', '=', 'locations.id') // Replace with actual foreign key
            ->leftJoin('users as user', 'genting_bookings.user_id', '=', 'user.id')
            ->leftJoin('companies as company', 'genting_bookings.user_id', '=', 'company.user_id')
            ->when($user->type === 'agent', function ($query) use ($user) {
                $staffIds = $user->staff()->pluck('id');
                $query->whereIn('genting_bookings.user_id', $staffIds->push($user->id));
            })->orderBy('bookings.booking_date', 'desc');

        return QueryBuilder::for($query)
            ->allowedFilters([
                'hotel_name',
                'package',
                'check_in',
                'check_out',
                'booking_id',
                'users.agent_code',
                'users.company.agent_name',
                'users.email',
                'bookings.booking_type',
                'genting_bookings.currency',
                'total_cost',
                AllowedFilter::exact('booking_id'),
                AllowedFilter::exact('bookings.booking_unique_id'),
                AllowedFilter::exact('booking_status', 'bookings.booking_status'),
                $globalSearch,
            ])
            ->allowedSorts(['booking_id', 'package', 'bookings.booking_type', 'bookings.booking_unique_id', 'bookings.booking_date', 'bookings.deadline_date', 'total_cost', 'check_in', 'check_out', 'hotel_name']);
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
            ->column('bookings.booking_unique_id', label: 'Booking Id', sortable: true, searchable: true, as: function ($value, $model) {
                return $model->booking_unique_id ?? 'N/A';
            })
            ->column('users.agent_code', label: 'Agent Code', as: function ($value, $model) {
                return $model->agent_code ?? 'N/A';
            })
            ->column(key: 'agent_info', label: 'Agent Info', as: function ($column, $model) {
                return optional(optional($model->users)->company)->agent_name . ' (' . $model->email . ')';
            })
            ->column('hotel_name', label: 'Hotel Name', sortable: true, searchable: true)
            ->column('bookings.booking_type', label: 'Booking Type', sortable: true, searchable: true, hidden: true, as: function ($value, $model) {
                return $model->booking_type ?? 'N/A';
            })
            ->column('package', label: 'Package', sortable: true, searchable: true, hidden: true)
            ->column('location.name', label: 'Location', hidden: true, as: function ($value, $model) {
                return $model->location->name ?? 'N/A';
            })
            ->column('check_in', label: 'CheckIn', sortable: true, searchable: false, hidden: true)
            ->column('check_out', label: 'CheckOut', sortable: true, searchable: false, hidden: true)
            ->column(
                'bookings.booking_date',
                label: 'Booking Date',
                sortable: true,
                searchable: false,
                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return convertToUserTimeZone($model->booking_date);
                }
            )
            ->column(
                'bookings.deadline_date',
                label: 'Deadline Date',
                sortable: true,
                searchable: false,
                hidden: true,
                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return convertToUserTimeZone($model->deadline_date);
                }
            )
            ->column('currency', label: 'Currency', sortable: false, searchable: true)
            ->column('total_cost', label: 'Total Cost', sortable: true, searchable: true)
            ->column(
                'booking_status',
                label: 'Booking Status',
                sortable: false,
                searchable: true,
                as: function ($value, $model) {
                    return $value === 'confirmed' ? 'Confirmed/Unpaid' : ucfirst($value ?? 'N/A');
                }
            )
            ->column('actions', label: 'Actions', as: function ($value, $model) {
                return new HtmlString(view('gentingBooking.partials.booking_actions', [
                    'model' => $model,
                ])->render());
            })
            ->paginate(15)
            ->bulkAction(
                label: 'Delete Selected',
                each: fn(GentingBooking $genting) => $genting->delete(),
                confirm: 'Are you sure you want to delete selected bookings?',
                confirmButton: 'Yes, Delete',
                cancelButton: 'Cancel',
            )
            ->export();
    }

}