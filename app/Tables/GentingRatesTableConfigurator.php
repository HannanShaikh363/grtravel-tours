<?php

namespace App\Tables;

use App\Models\GentingRate;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use Illuminate\Support\Facades\Gate;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Collection;

class GentingRatesTableConfigurator extends AbstractTable
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
                        ->orWhere('genting_rates.id', 'LIKE', "%{$value}%")
                        ->orWhere('hotel_name', 'LIKE', "%{$value}%")
                        ->orWhere('room_type', 'LIKE', "%{$value}%")
                        ->orWhere('currency', 'LIKE', "%{$value}%")
                        ->orWhere('price', 'LIKE', "%{$value}%")
                        ->orWhere('bed_count', 'LIKE', "%{$value}%")
                        ->orWhere('room_capacity', 'LIKE', "%{$value}%")
                        ->orWhere('genting_packages.package', 'LIKE', "%{$value}%")
                        ->orWhere('genting_rates.created_at', 'LIKE', "%{$value}%");
                });
            });
        });

        $query = GentingRate::query()
                    ->join('genting_hotels', 'genting_rates.genting_hotel_id', '=', 'genting_hotels.id')
                    ->join('genting_packages', 'genting_rates.genting_package_id', '=', 'genting_packages.id')
                    ->select('genting_rates.*', 'genting_hotels.hotel_name as hotelName', 'genting_packages.package as gentingRatePackage')
                    ->orderByDesc('created_at')
                    ->with('gentingHotel', 'gentingPackage');

                    return QueryBuilder::for($query) 
                    ->allowedFilters([
                        AllowedFilter::exact('id'),
                        AllowedFilter::callback('gentingRatePackage', function ($query, $value) {
                            $query->whereHas('gentingPackage', function ($q) use ($value) {
                                $q->where('package', 'LIKE', "%{$value}%");
                            });
                        }),
                        AllowedFilter::exact('room_type'), 
                        AllowedFilter::callback('hotelName', function ($query, $value) {
                            $query->whereHas('gentingHotel', function ($q) use ($value) {
                                $q->where('hotel_name', 'LIKE', "%{$value}%");
                            });
                        }),
                        AllowedFilter::exact('created_at'),
                        AllowedFilter::exact('price'),
                        AllowedFilter::exact('currency'),
                        AllowedFilter::exact('bed_count'),
                        AllowedFilter::exact('effective_date'),
                        AllowedFilter::exact('expiry_date'),
                        AllowedFilter::exact('room_capacity'),
                        $globalSearch,
                    ])
                    ->allowedSorts([
                        'id', 
                        'hotelName', 
                        'room_type', 
                        'price',
                        'created_at',
                        'currency',
                        'bed_count',
                        'room_capacity',
                        'effective_date',
                        'expiry_date'
                    ]);
                
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
        ->column(key: 'id', label: 'ID', sortable: true, searchable: true)
        ->column(key: 'hotelName', label: 'Hotel Name', sortable: true, searchable: true) // Fix key
        ->column(key: 'gentingRatePackage', label: 'Package', searchable: true) // Fix key
        ->column(key: 'room_type', label: 'Room Type', searchable: true) 
        ->column(key: 'price', label: 'Price', sortable: false, searchable: true)
        ->column(key: 'currency', label: 'Currency', sortable: false, searchable: true) // Fix casing issue
        ->column(key: 'bed_count', label: 'Bed Count', sortable: true, searchable: true)
        ->column(key: 'room_capacity', label: 'Room Capacity', sortable: true, searchable: true)
        ->column(key: 'effective_date', sortable: true)
        ->column(key: 'expiry_date', sortable: true)
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
            if (Gate::allows('update genting')) {
                $action = route('genting.rates.edit', $model->id);
                $slot = 'Update';
                return view('table.component.actions', compact('model', 'action', 'slot')); // Use a view to render buttons
            }
        })
        ->paginate(15)
        ->column('id', sortable: false, exportAs: false)->bulkAction(
            label: 'Delete',
            // each: fn(Rate $rate) => $rate->delete(),
            each: function (GentingRate $rate) {
        
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

    }
    public function deleteRate(GentingRate $rate)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete genting')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete rate.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        GentingRate::where('id', $rate->id)->delete();

        // Show success message after deletion
        Toast::info('Rate has been deleted!')->autoDismiss(3);
    }
    
}
