<?php

namespace App\Tables;

use App\Models\Tour;
use App\Models\TourDestination;
use App\Models\TourRate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RegisterTourTableConfigurator extends AbstractTable
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
                        ->orWhere('tour_rates.id', 'LIKE', "%{$value}%")
                        ->orWhere('tour_destinations.name', 'LIKE', "%{$value}%")
                        ->orWhere('package', 'LIKE', "%{$value}%")
                        ->orWhere('locations.name', 'LIKE', "%{$value}%")
                        ->orWhere('hours', 'LIKE', "%{$value}%")
                        ->orWhere('price', 'LIKE', "%{$value}%")
                        ->orWhere('currency', 'LIKE', "%{$value}%")
                        ->orWhere('adult', 'LIKE', "%{$value}%")
                        ->orWhere('child', 'LIKE', "%{$value}%");
                });
            });
        });

        return QueryBuilder::for(TourRate::query()
        ->join('tour_destinations', 'tour_rates.tour_destination_id', '=', 'tour_destinations.id') // Join tour_destinations
        ->join('locations', 'tour_destinations.location_id', '=', 'locations.id') // Join locations
        ->orderByDesc('created_at')
        ->select('tour_rates.*',        
            'tour_destinations.name as destination_name',
            'locations.name as location_name',
            'tour_destinations.hours as hours',
            'tour_destinations.adult as adult',
            'tour_destinations.child as child',
        ) // Select columns from the main table
        ->with(['tourDestination', 'tourDestination.location']))        // Load related TourDestination and its associated Location
            ->allowedFilters([
                AllowedFilter::exact('id', 'tour_rates.id'),
                AllowedFilter::exact('destination_name','tour_destinations.name'), // Filter by TourDestination name
                AllowedFilter::exact('location_name','locations.name'), // Filter by Location name
                AllowedFilter::exact('package'), // From TourRates
                AllowedFilter::exact('hours','tour_destinations.hours'), // From TourDestination
                AllowedFilter::exact('price'), // From TourRates
                AllowedFilter::exact('currency'), // From TourRates
                AllowedFilter::exact('seating_capacity'), // From TourRates
                AllowedFilter::exact('luggage_capacity'), // From TourRates
                AllowedFilter::exact('adult','tour_destinations.adult'),
                AllowedFilter::exact('child','tour_destinations.child'),
                AllowedFilter::exact('remarks'), // From TourRates
                AllowedFilter::exact('effective_date'), // From TourRates
                AllowedFilter::exact('expiry_date'), // From TourRates
                $globalSearch, // Assuming global search filter
            ])
            ->allowedSorts([
                'tour_rates.id as rates_id',
                'destination_name',
                'tour_destinations.name', // Sort by TourDestination name
                'location_name', // Sort by Location name
                'tour_destinations.hours',
                'tour_destinations.child',
                'tour_destinations.adult',
                'package',
                'price',
                'currency',
                'seating_capacity',
                'luggage_capacity',
                'remarks',
                'effective_date',
                'expiry_date',
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
            ->column(key: 'destination_name', label: 'Name', searchable: true, sortable: true)
            ->column(key: 'package', label: 'Package', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'location_name', label: 'Location Name', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'hours', label: 'Hours', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'price', label: 'Price', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'currency', label: 'Currency', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'adult', label: 'Adult Ticket Price', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'child', label: 'Child Ticket Price', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'seating_capacity', label: 'Seating Capacity', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'luggage_capacity', label: 'Luggage Capacity', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'effective_date', label: 'Effective Date', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'expiry_date', label: 'Expiry Date', searchable: true, sortable: true, canBeHidden: true)
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
            ->column(key: 'actions', label: 'Actions', as: function ($column, $model) {

                $action = route('tour.edit', $model->id);
                $slot = 'Update';
                return view('table.component.actions', compact('model', 'action', 'slot')); // Use a view to render buttons
            })
            ->paginate(15)
            ->column('rates_id', sortable: true)->bulkAction(
                label: 'Delete',
                each: fn(TourRate $tour) => $this->deleteTour($tour),
                before: fn() => info('Deleting the selected tour'),
                // after: fn() => Toast::info('Tour have been deleted!'),
                confirm: 'Deleting tour data?',
                confirmText: 'Are you sure you want to delete the tour data?',
                confirmButton: 'Yes, delete all selected rows!',
                cancelButton: 'No, do not delete!',
            )
            ->export();
    }
    public function deleteTour(TourRate $tour)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete tour')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete tour.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        $tour->delete();

        // Show success message after deletion
        Toast::info('Tour has been deleted!')->autoDismiss(3);
    }
}
