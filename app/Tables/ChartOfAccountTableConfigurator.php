<?php

namespace App\Tables;

use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Facades\Gate;

class ChartOfAccountTableConfigurator extends AbstractTable
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
                        ->orWhere('account_code', 'LIKE', "%{$value}%")
                        ->orWhere('account_name', 'LIKE', "%{$value}%")
                        ->orWhere('nature', 'LIKE', "%{$value}%")
                        ->orWhere('level', 'LIKE', "%{$value}%")
                        ->orWhere('currency', 'LIKE', "%{$value}%")
                        ->orWhere('status', 'LIKE', "%{$value}%");
                });
            });
        });

        $user = auth()->user();

        $query = ChartOfAccount::query();

        return QueryBuilder::for($query)
            ->allowedFilters([
                'id',
                'account_code',
                'account_name',
                'nature',
                'level',
                'status',
                $globalSearch,
            ])
            ->allowedSorts(['id','account_code','account_name','nature','level', 'status']);
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
            ->column('id', label: 'Id', sortable: true, searchable: true)
            ->column('account_code', label: 'Account Code',sortable: true, searchable: true, as: function ($value, $model) {
                return $model->account_code ?? 'N/A';
            })
            ->column('account_name', label: 'Agent Name', as: function ($value, $model) {
                return $model->account_name ?? 'N/A';
            })
            ->column('nature', label: 'Nature', sortable: true, searchable: true)
            ->column('level', label: 'Subsidary Account',sortable: true, searchable: true, as: function ($value, $model) {
                return $model->level ?? 'N/A';
            })
            // ->column('currency', label: 'Currency', sortable: true, searchable: true)
            ->column('status', label: 'Status', as: function ($value, $model) {
                return $model->status === 1 ? 'Active' : 'Inactive';
            })
            
            ->column(key: 'actions', label: 'Actions', exportAs: false, as: function ($column, $model) {
                if (Gate::allows('update account')) {
                    $action = route('chart_of_account.edit', $model->id);
                    $slot = 'Update';
                    return view('table.component.actions', compact('model', 'action', 'slot')); // Use a view to render buttons
                }
            })
            ->paginate(15)
            // ->bulkAction(
            //     label: 'Delete Selected',
            //     each: fn(GentingBooking $genting) => $genting->delete(),
            //     confirm: 'Are you sure you want to delete selected bookings?',
            //     confirmButton: 'Yes, Delete',
            //     cancelButton: 'Cancel',
            // )
            ->export();
    }
}
