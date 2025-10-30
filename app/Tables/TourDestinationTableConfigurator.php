<?php

namespace App\Tables;

use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use App\Models\TourDestination;
use Illuminate\Support\Facades\Gate;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Collection;

class TourDestinationTableConfigurator extends AbstractTable
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
                        ->orWhere('name', 'LIKE', "%{$value}%")
                        ->orWhere('hours', 'LIKE', "%{$value}%")
                        ->orWhere('ticket_currency', 'LIKE', "%{$value}%")
                        ->orWhere('adult', 'LIKE', "%{$value}%")
                        ->orWhere('child', 'LIKE', "%{$value}%")
                        ->orWhereHas('location', function ($q) use ($value) {
                            $q->where('name', 'LIKE', "%{$value}%");
                        });
                });
            });
        });

        return QueryBuilder::for(TourDestination::query()
            ->with(['location'])) // Load related TourDestination and its associated Location
            ->orderByDesc('created_at')
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::exact('name'), // Filter by TourDestination name
                // AllowedFilter::exact('location.name'), // Filter by Location name
                AllowedFilter::exact('hours'), // From TourDestination
                AllowedFilter::exact('ticket_currency'), // From TourRates
                AllowedFilter::exact('adult'),
                AllowedFilter::exact('child'),
                AllowedFilter::exact('closing_day'),
                $globalSearch, // Assuming global search filter
            ])
            ->allowedSorts([
                'id', 
                'name', // Sort by TourDestination name
                'hours',
                'ticket_currency',
                'adult',
                'child',
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
            ->column(key: 'name', label: 'Name', searchable: true, sortable: true)
            ->column(key: 'location.name', label: 'Location Name', searchable: false, sortable: false, canBeHidden: true)
            ->column(key: 'hours', label: 'Hours', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'ticket_currency', label: 'Currency', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'adult', label: 'Adult Ticket Price', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'child', label: 'Child Ticket Price', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'closing_day', label: 'Closed', searchable: true, sortable: true, canBeHidden: true)
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

                $action = route('tour.destination.edit', $model->id);
                $slot = 'Update';
                return view('table.component.actions', compact('model', 'action', 'slot')); // Use a view to render buttons
            })
            ->paginate(15)
            ->column('id', sortable: true)->bulkAction(
                label: 'Delete',
                each: fn(TourDestination $tourDestination) => $this->deleteTour($tourDestination),
                before: fn() => info('Deleting the selected tour destination'),
                // after: fn() => Toast::info('Tour have been deleted!'),
                confirm: 'Deleting tour destination data?',
                confirmText: 'Are you sure you want to delete the tour destination data?',
                confirmButton: 'Yes, delete all selected rows!',
                cancelButton: 'No, do not delete!',
            )
            ->export();
    }
    public function deleteTour(TourDestination $tourDestination)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete tour')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete tour.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        $tourDestination->delete();

        // Show success message after deletion
        Toast::info('Tour Destination has been deleted!')->autoDismiss(3);
    }
}
