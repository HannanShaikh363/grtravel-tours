<?php

namespace App\Tables;

use App\Models\Rate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class RateTableConfigurator extends AbstractTable
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
                        ->orWhere('rates.name', 'LIKE', "%{$value}%")
                        ->orWhereHas('fromLocation', function ($q) use ($value) {
                            $q->where('name', 'LIKE', "%{$value}%");
                        })
                        ->orWhereHas('toLocation', function ($q) use ($value) {
                            $q->where('name', 'LIKE', "%{$value}%");
                        })
                        ->orWhereHas('transport', function ($q) use ($value) {
                            $q->where('vehicle_make', 'LIKE', "%{$value}%");
                        })
                        ->orWhereHas('transport', function ($q) use ($value) {
                            $q->where('vehicle_model', 'LIKE', "%{$value}%");
                        })
                        ->orWhere('rate', 'LIKE', "%{$value}%")
                        ->orWhere('currency', 'LIKE', "%{$value}%")
                        ->orWhere('rates.vehicle_seating_capacity', 'LIKE', "%{$value}%")
                        ->orWhere('rates.vehicle_luggage_capacity', 'LIKE', "%{$value}%")
                        ->orWhere('route_type', 'LIKE', "%{$value}%");
                });
            });
        });

        $query = QueryBuilder::for(Rate::query())
            // ->leftJoin('transports as transport', 'rates.transport_id', '=', 'transport.id') // Changed to leftJoin
            // ->select('rates.id as rate_id','rates.*', 'transport.vehicle_model as transport_name')
            ->with('fromLocation', 'toLocation','transport')
            ->orderByDesc('created_at')
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::exact('name'),
                AllowedFilter::exact('rate'),
                AllowedFilter::exact('currency'),
                // AllowedFilter::exact('transport.vehicle_make'),
                // AllowedFilter::exact('transport.vehicle_model'),
                AllowedFilter::exact('vehicle_luggage_capacity'),
                AllowedFilter::exact('vehicle_seating_capacity'),
                AllowedFilter::exact('time_remarks'),
                AllowedFilter::exact('remarks'),
                AllowedFilter::exact('hours'),

                // Use callback for related locations filtering
                AllowedFilter::callback('fromLocation.name', function ($query, $value) {
                    $query->whereHas('fromLocation', function ($q) use ($value) {
                        $q->where('name', 'LIKE', "%{$value}%");
                    });
                }),
                AllowedFilter::callback('toLocation.name', function ($query, $value) {
                    $query->whereHas('toLocation', function ($q) use ($value) {
                        $q->where('name', 'LIKE', "%{$value}%");
                    });
                }),
                AllowedFilter::callback('transport.vehicle_make', function ($query, $value) {
                    $query->whereHas('transport', function ($q) use ($value) {
                        $q->where('vehicle_make', 'LIKE', "%{$value}%");
                    });
                }),

                AllowedFilter::callback('transport.vehicle_model', function ($query, $value) {
                    $query->whereHas('transport', function ($q) use ($value) {
                        $q->where('vehicle_model', 'LIKE', "%{$value}%");
                    });
                }),

                $globalSearch,
            ])
            ->allowedSorts(['id', 'name', 'rate', 'vehicle_seating_capacity', 'vehicle_luggage_capacity', 'effective_date', 'expiry_date', 'time_remarks', 'remarks', 'hours']);
   
            // dd($query->getQuery()->toSql(), $query->getQuery()->getBindings());

        return $query;

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
            ->column('id', label: 'Name', sortable: true, searchable: true)
            ->column(key: 'name', label: 'Name', sortable: true)
            ->column(key: 'fromLocation.name', label: 'From', searchable: true) // Adjusted to reference relationship
            ->column(key: 'toLocation.name', label: 'To', searchable: true) // Adjusted to reference relationship
            ->column(key: 'transport.vehicle_make', label: 'Vehicle Make', sortable: false, searchable: true)
            ->column(key: 'transport.vehicle_model', label: 'Vehicle Model', sortable: false, searchable: true)
            ->column(key: 'vehicle_seating_capacity', label: 'Seating Capacity', sortable: true, searchable: true)
            ->column(key: 'vehicle_luggage_capacity', label: 'Luggage Capacity', sortable: true, searchable: true)
            ->column(key: 'rate', canBeHidden: false, sortable: true,)
            ->column(key: 'package', label: 'Package', canBeHidden: false, sortable: true,)
            ->column(key: 'currency')
            ->column(key: 'effective_date', sortable: true)
            ->column(key: 'expiry_date', sortable: true)
            ->column(key: 'route_type', label: 'Route', sortable: true)
            ->column(key: 'time_remarks', label: 'Time Remarks', sortable: true, searchable: true)
            ->column(key: 'remarks', label: 'Remarks', sortable: true, searchable: true)
            ->column(key: 'hours', label: 'Hours', sortable: true, searchable: true)
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
            ->column(key: 'actions', label: 'Actions', exportAs: false, as: function ($column, $model) {
                if (Gate::allows('update rate')) {
                $action = route('rate.edit', $model->id);
                $slot = 'Update';
                return view('table.component.actions', compact('model', 'action', 'slot')); // Use a view to render buttons
                }
            })
            ->paginate(15)
            ->column('id', sortable: false, exportAs: false)->bulkAction(
                label: 'Delete',
                // each: fn(Rate $rate) => $rate->delete(),
                each: function (Rate $rate) {
            
                    // Rate::where('id', $rate->id)->delete();
                    $this->deleteRate($rate);
                },
                before: fn() => info('Deleting the selected rate'),
                // after: fn() => Toast::info('Rate have been deleted!'),
                confirm: 'Deleting rate data?',
                confirmText: 'Are you sure you want to delete the rate data?',
                confirmButton: 'Yes, delete all selected rows!',
                cancelButton: 'No, do not delete!'
            )
            ->export();
        // ->searchInput()
        // ->selectFilter()
        // ->withGlobalSearch()
        // ->bulkAction()
        // ->export()
    }
     public function deleteRate(Rate $rate)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete rate')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete rate.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
       Rate::where('id', $rate->id)->delete();

        // Show success message after deletion
        Toast::info('Rate has been deleted!')->autoDismiss(3);
    }
}
