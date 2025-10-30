<?php

namespace App\Tables;

use App\Models\Transport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class TransportTableConfigurator extends AbstractTable
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
                        ->orWhere('vehicle_make', 'LIKE', "%{$value}%")
                        ->orWhere('vehicle_model', 'LIKE', "%{$value}%")
                        ->orWhere('package', 'LIKE', "%{$value}%")
                        ->orWhere('vehicle_seating_capacity', 'LIKE', "%{$value}%")
                        ->orWhere('vehicle_luggage_capacity', 'LIKE', "%{$value}%");
                });
            });
        });

        return QueryBuilder::for(Transport::query()
            ->with('fleetbookings', 'insurance', 'driver', 'rates'))
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::exact('vehicle_make'),
                AllowedFilter::exact('vehicle_model'),
                AllowedFilter::exact('vehicle_seating_capacity'),
                AllowedFilter::exact('vehicle_luggage_capacity'),
                AllowedFilter::exact('package'),
                $globalSearch,
            ])
            ->allowedSorts(['id', 'vehicle_make', 'vehicle_model', 'vehicle_seating_capacity', 'vehicle_luggage_capacity', 'package']);
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
            ->column(key: 'vehicle_make', label: 'Vehicle Make', sortable: true)
            ->column(key: 'vehicle_model', label: 'Vehicle Model', searchable: true)
            ->column(key: 'vehicle_seating_capacity', label: 'Seating Capacity', searchable: true)
            ->column(key: 'vehicle_luggage_capacity', label: 'Luggage Capacity', searchable: true, sortable: true)
            ->column(key: 'vehicle_seating_capacity', label: 'Seating Capacity', sortable: true, searchable: true)
            ->column(key: 'package', label: 'Package', sortable: true, searchable: true)
            ->column(key: 'actions', label: 'Actions', as: function ($column, $model) {
                if (Gate::allows('update transport')) {
                $action = route('transport.edit', $model->id);
                $slot = 'Update';
                return view('table.component.actions', compact('model', 'action', 'slot'));
                }
            })
            ->paginate(15)
            ->column('id', sortable: true)
            ->bulkAction(
                label: 'Delete',
                each: fn(Transport $transport) => $this->deleteTransport($transport),
                before: fn() => info('Deleting the selected transport'),
                confirm: 'Deleting transport data?',
                confirmText: 'Are you sure you want to delete the transport data?',
                confirmButton: 'Yes, delete all selected rows!',
                cancelButton: 'No, do not delete!',
            )
            ->export();
    }

    public function deleteTransport(Transport $transport)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete transport')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete transport.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        $transport->delete();

        // Show success message after deletion
        Toast::info('Transport has been deleted!')->autoDismiss(3);
    }

  
}
