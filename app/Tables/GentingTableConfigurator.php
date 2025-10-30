<?php

namespace App\Tables;

use App\Models\GentingHotel;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use Illuminate\Support\Facades\Gate;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Collection;

class GentingTableConfigurator extends AbstractTable
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
                        ->orWhere('id', 'LIKE', "%{$value}%")
                        ->orWhere('hotel_name', 'LIKE', "%{$value}%")
                        ->orWhere('hotel_code', 'LIKE', "%{$value}%")
                        ->orWhere('closing_day', 'LIKE', "%{$value}%")
                        ->orWhere('created_at', 'LIKE', "%{$value}%");
                });
            });
        });

        return QueryBuilder::for(GentingHotel::query()
            ->with(['location'])) // Load related TourDestination and its associated Location
            ->orderByDesc('created_at')
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::exact('hotel_name'), // Filter by TourDestination name
                AllowedFilter::callback('location.name', function ($query, $value) {
                    $query->whereHas('location', function ($q) use ($value) {
                        $q->where('location.name', 'LIKE', "%{$value}%");
                    });
                }),
                // AllowedFilter::exact('hotel_code'), // From TourDestination
                // AllowedFilter::exact('closing_day'), // From TourRates
                AllowedFilter::exact('created_at'),
                $globalSearch, // Assuming global search filter
            ])
            ->allowedSorts([
                'id', 
                'hotel_name', // Sort by TourDestination name
                'hotel_code',
                'created_at',
                'closing_day'

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
            ->column(key: 'hotel_name', label: 'Hotel Name', searchable: true, sortable: true)
            ->column(key: 'location.name', label: 'Location Name', searchable: false, sortable: false, canBeHidden: true)
            // ->column(key: 'hotel_code', label: 'Hotel Code', searchable: true, sortable: true, canBeHidden: true)
            // ->column(key: 'closing_day', label: 'Closing Day', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'created_at', label: 'Created at', searchable: false, sortable: false, canBeHidden: true)
            ->column(key: 'updated_at', label: 'Updated at', searchable: false, sortable: false, canBeHidden: true)
            ->column(key: 'actions', label: 'Actions', as: function ($column, $model) {

                $action = route('genting.edit', $model->id);
                $slot = 'Update';
                return view('table.component.actions', compact('model', 'action', 'slot')); // Use a view to render buttons
            })
            ->paginate(15)
            ->column('id', sortable: true)->bulkAction(
                label: 'Delete',
                each: fn(GentingHotel $gentingHotel) => $this->deleteGenting($gentingHotel),
                before: fn() => info('Deleting the selected tour destination'),
                // after: fn() => Toast::info('Tour have been deleted!'),
                confirm: 'Deleting tour destination data?',
                confirmText: 'Are you sure you want to delete the tour destination data?',
                confirmButton: 'Yes, delete all selected rows!',
                cancelButton: 'No, do not delete!',
            )
            ->export();
    }
    public function deleteGenting(GentingHotel $gentingHotel)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete genting')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete genting hotel.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        $gentingHotel->delete();

        // Show success message after deletion
        Toast::info('Genting Hotel has been deleted!')->autoDismiss(3);
    }
}
