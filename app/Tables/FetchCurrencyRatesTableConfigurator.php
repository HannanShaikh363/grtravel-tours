<?php

namespace App\Tables;

use App\Models\CurrencyRate;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use Illuminate\Support\Facades\Gate;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Collection;

class FetchCurrencyRatesTableConfigurator extends AbstractTable
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
                        ->orWhere('currency_rates.id', 'LIKE', "%{$value}%")
                        ->orWhere('base_currency', 'LIKE', "%{$value}%")
                        ->orWhere('target_currency', 'LIKE', "%{$value}%")
                        ->orWhere('rate', 'LIKE', "%{$value}%")
                        ->orWhere('rate_date', 'LIKE', "%{$value}%")
                        ->orWhere('currency_rates.created_at', 'LIKE', "%{$value}%");
                });
            });
        });

        $query = CurrencyRate::query()
                    ->select('currency_rates.*')
                    ->orderByDesc('created_at');
                    // ->with('gentingHotel', 'gentingPackage');

                    return QueryBuilder::for($query) 
                    ->allowedFilters([
                        AllowedFilter::exact('created_at'),
                        AllowedFilter::exact('base_currency'),
                        AllowedFilter::exact('target_currency'),
                        AllowedFilter::exact('rate'),
                        AllowedFilter::exact('rate_date'),
                        $globalSearch,
                    ])
                    ->allowedSorts([
                        'id', 
                        'base_currency', 
                        'target_currency', 
                        'rate',
                        'created_at',
                        'rate_date'
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
        ->column(key: 'base_currency', label: 'Base Currency', sortable: true, searchable: true) // Fix key
        ->column(key: 'target_currency', label: 'Target Currency', searchable: true) // Fix key
        ->column(key: 'rate', label: 'Rate', searchable: true) 
        ->column(key: 'rate_date', label: 'Rate Date', sortable: false, searchable: true)
        ->column(key: 'created_at', label: 'Created At', sortable: false, searchable: true) // Fix casing issue
        ->paginate(15)
        ->column('id', sortable: false, exportAs: false)->bulkAction(
            label: 'Delete',
            // each: fn(Rate $rate) => $rate->delete(),
            each: function (CurrencyRate $rate) {
        
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
    public function deleteRate(CurrencyRate $rate)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete rate')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete rate.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        CurrencyRate::where('id', $rate->id)->delete();

        // Show success message after deletion
        Toast::info('Rate has been deleted!')->autoDismiss(3);
    }
    
}
