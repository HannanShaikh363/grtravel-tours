<?php

namespace App\Tables;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class LocationTableConfigurator extends AbstractTable
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
                        ->where('locations.name', 'LIKE', "%{$value}%")
                        ->orWhere('countries.name', 'LIKE', "%{$value}%")
                        ->orWhere('cities.name', 'LIKE', "%{$value}%");
                });
            });
        });

        return QueryBuilder::for(Location::query()
            ->join('countries', 'locations.country_id', '=', 'countries.id')
            ->leftJoin('cities', 'locations.city_id', '=', 'cities.id') // Left join to include locations without city_id
            ->select('locations.*', 'countries.name as country_name', 'cities.name as city_name') // Select columns with aliases
            ->with('city', 'country'))
            ->orderByDesc('created_at')
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::exact('name'),
                AllowedFilter::exact('countries.name'),
                AllowedFilter::exact('cities.name'),
                AllowedFilter::exact('latitude'),
                AllowedFilter::exact('longitude'),
                $globalSearch,
            ])
            ->allowedSorts(['name', 'country_name', 'city_name', 'id']); // Sorting on alias columns
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
            ->defaultSort('name') // Sorting by the location name

            // Display Location Name
            ->column(key: 'name', label: 'Name', searchable: true, sortable: true)

            // Display Latitude
            ->column(key: 'latitude', label: 'Latitude', searchable: true, canBeHidden: true)

            // Display Longitude
            ->column(key: 'longitude', label: 'Longitude', searchable: true, canBeHidden: true)

            // Display Country Name (using alias)
            ->column(key: 'country_name', label: 'Country', sortable: true, canBeHidden: true)

            // Display City Name (using alias and handle null values)
            ->column(
                key: 'city_name',
                label: 'City',
                sortable: true,
                canBeHidden: true,
                as: function ($column, $model) {
                    return $model->city_name ?? 'No City Assigned'; // Handle null city_name
                }
            )

            // Display Actions
            ->column(key: 'actions', label: 'Actions', as: function ($column, $model) {
                if (Gate::allows('update location')) {
                $action = route('location.edit', $model->id);
                $slot = 'Update';
                return view('table.component.actions', compact('model', 'action', 'slot'));
                }
            })

            // Pagination
            ->paginate(15)
            ->column('id', sortable: true)
            // Bulk Action: Delete
            ->bulkAction(
                label: 'Delete',
                each: fn(Location $location) => $this->deleteLocation($location),
                before: fn() => info('Deleting the selected location'),
                // after: fn() => Toast::info('Location(s) have been deleted!'),
                confirm: 'Deleting Location Data?',
                confirmText: 'Are you sure you want to delete the location data?',
                confirmButton: 'Yes, Delete Selected Row(s)!',
                cancelButton: 'No, Do Not Delete!',
            )

            // Enable Export
            ->export();
    }
    public function deleteLocation(Location $location)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete location')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete location.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        $location->delete();

        // Show success message after deletion
        Toast::info('Location has been deleted!')->autoDismiss(3);
    }
}
