<?php

namespace App\Tables;

use App\Models\Booking;
use App\Models\CancellationPolicies;
use App\Models\FleetBooking;
use App\Models\Rate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use ProtoneMedia\Splade\AbstractTable;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Facades\Gate;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AllBookingConfigurator extends AbstractTable
{

    /**
     * Create a new instance.
     *
     * @return void
     */
    public $cancellationBooking;
    public function __construct()
    {
        //

        $this->cancellationBooking = CancellationPolicies::where('active', 1)->get();
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
        // Get the authenticated user
        $user = auth()->user();

        $globalSearch = AllowedFilter::callback('global', function ($query, $value) {
            $query->where(function ($query) use ($value) {
                Collection::wrap($value)->each(function ($value) use ($query) {
                    $query
                        ->orWhere('bookings.id', 'LIKE', "%{$value}%")
                        ->orWhere('booking_unique_id', 'LIKE', "%{$value}%")
                        ->orWhere('amount', 'LIKE', "%{$value}%")
                        ->orWhere('currency', 'LIKE', "%{$value}%")
                        ->orWhere('booking_date', 'LIKE', "%{$value}%")
                        ->orWhere('service_date', 'LIKE', "%{$value}%")
                        ->orWhere('deadline_date', 'LIKE', "%{$value}%")
                        ->orWhere('booking_status', 'LIKE', "%{$value}%")
                        ->orWhere('booking_type', 'LIKE', "%{$value}%")
                        ->orWhere('user.agent_code', 'LIKE', "%{$value}%")
                        ->orWhere('user.email', 'LIKE', "%{$value}%");
                });
            });
        });
        $query = Booking::query()
                ->leftJoin('users as user', 'bookings.user_id', '=', 'user.id')
                ->select('bookings.id as booking_id' ,'bookings.*', 'user.*');
        
        // Filter based on user type
        if ($user->type === 'agent') {

            $query->where('bookings.user_id', $user->id); // Only get bookings for this agent
        }

        $query->orderBy('bookings.booking_date', 'desc');
        return QueryBuilder::for($query)
            ->allowedFilters([
                AllowedFilter::exact('booking_id', 'bookings.id'),
                AllowedFilter::exact('booking_unique_id'),
                AllowedFilter::exact('booking_date'),
                AllowedFilter::exact('service_date'),
                AllowedFilter::exact('amount'),
                AllowedFilter::exact('currency'),
                AllowedFilter::exact('deadline_date'),
                AllowedFilter::exact('booking_type'),
                AllowedFilter::exact('booking_status'),
                AllowedFilter::exact('user_id','user.agent_code'),
                $globalSearch,
            ])
            ->allowedSorts(['booking_id','booking_unique_id','bookings.id', 'user_id', 'booking_date', 'service_date', 'currency', 'amount', 'deadline_date', 'booking_status']);
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
            ->column('booking_id', label: 'Id', sortable: true, searchable: true)
            ->column(key: 'booking_unique_id', label: 'Booking Id', sortable: true, searchable: true, as: function ($column, $model) {
                if ($model->booking_type == 'tour' || $model->booking_type == 'ticket') {
                    $action = route('tour_booking.details', $model->booking_id);
                }
                else if ($model->booking_type == 'genting_hotel') {
                    $action = route('genting_booking.details', $model->booking_id);
                }
                else if ($model->booking_type == 'hotel') {
                    $action = route('auth.hotelBooking_details', $model->booking_id);
                }
                else{
                    $action = route('booking.details', $model->booking_id);
                }
                $slot = $model->booking_unique_id;
                return view('table.component.actions', compact('model', 'action', 'slot')); // Use a view to render buttons
                
            })
            ->column('user_id', label: 'Agent Code', sortable: true, searchable: true, as: function ($column, $model) {
                return optional($model->user)->agent_code;
            })
            ->column('booked_by', label: 'Booked By', sortable: false, searchable: false, as: function ($column, $model) {
                return optional($model->user)->first_name . ' ' . optional($model->user)->last_name . ' (' . optional($model->user)->email . ')';
            })
            ->column(key: 'booking_date', label: 'Booking Date', sortable: true)
            ->column(key: 'service_date', label: 'Service Date', searchable: true) // Adjusted to reference relationship
            ->column(key: 'deadline_date', label: 'Deadline Date', searchable: true) // Adjusted to reference relationship
            ->column(key: 'currency', label: 'Currency', searchable: true) // Adjusted to reference relationship
            ->column(key: 'amount', label: 'Amount', searchable: true) // Adjusted to reference relationship
            ->column(key: 'booking_type', label: 'Booking Type', searchable: true) // Adjusted to reference relationship
            ->column('booking_status', label: 'Booking Status', sortable: false, searchable: true,
    as: function ($value, $model) {
        return $value === 'confirmed' ? 'Confirmed/Unpaid' : ucfirst($value ?? 'N/A');
    }
)
            ->column(key: 'actions', label: 'Actions', exportAs: false, as: function ($column, $model) {
                $cancellationBookingViaType = null;
                // Filter the collection by type
                
                $cancellationBookingViaType = $this->cancellationBooking->first(function ($policy) use ($model) {
                    return $policy->type == $model->booking_type;
                });

                // Set the target date and time
                $targetDate = Carbon::createFromFormat('Y-m-d H:i:s', $model->service_date); // Replace with your target date and time
                // Get the current date and time
                $currentDate = Carbon::now();
                // Calculate the difference in days
                
                $remainingDays = $currentDate->diffInDays($targetDate, false); // 'false' to allow negative values if targetDate is in the past
                $booking = FleetBooking::where('id', $model->booking_type_id)->first();
                if (Gate::allows('update booking')) {
                    
                    switch ($model->booking_type) {
                        case 'transfer':
                            $offline_payment = route('offlineTransaction');
                            $cancel_booking_route = route('deductionViaService', ['service_id' => $model->booking_type_id, 'service_type' => $model->booking_type]);
                            $fullRefund = route('fullRefund', ['service_id' => $model->booking_id, 'service_type' => $model->booking_type]);
                            break;
                        case 'tour':
                            $offline_payment = route('tourOfflineTransaction');
                            $cancel_booking_route = route('tourDeduction', ['service_id' => $model->booking_type_id, 'service_type' => $model->booking_type]);
                            $fullRefund = route('fullRefund', ['service_id' => $model->booking_id, 'service_type' => $model->booking_type]);
                            break;
                        case 'ticket':
                            $offline_payment = route('tourOfflineTransaction');
                            $cancel_booking_route = route('tourDeduction', ['service_id' => $model->booking_type_id, 'service_type' => $model->booking_type]);
                            $fullRefund = route('fullRefund', ['service_id' => $model->booking_id, 'service_type' => $model->booking_type]);
                            break;
                        case 'genting_hotel':
                            $offline_payment = route('gentingOfflineTransaction');
                            $cancel_booking_route = route('cancelledGentingBooking', ['service_id' => $model->booking_type_id, 'service_type' => $model->booking_type]);
                            $fullRefund = route('fullRefund', ['service_id' => $model->booking_id, 'service_type' => $model->booking_type]);
                            break;
                        default:
                            $offline_payment = '';
                            $cancel_booking_route = '';
                            $fullRefund = '';
                            break;
                    }


                    return view('all_booking.partials.actions', [
                        'model' => $model,
                        'offline_payment' => $offline_payment,
                        'cancellationBooking' => $cancellationBookingViaType,
                        'remainingDays' => $remainingDays,
                        'booking' => $booking,
                        'booking_uapprove' => isset($booking->id) ? route('booking.unapprove', ['id' => $booking->id]) : null,
                        'cancel_booking_route' => $cancel_booking_route,
                        'fullRefund' => $fullRefund,
                        'userWallet' => $model->user->credit_limit_currency. ' '.round($model->user->credit_limit, 2),

                        //                    'editRoute' => route('bookings.edit', $model->id),
                        //                    'deleteRoute' => route('bookings.destroy', $model->id),
                    ]);
                }
            })
            ->paginate(15)
            ->export();
    }

    public function query()
    {
        return Booking::query()->with('user');
    }
}
