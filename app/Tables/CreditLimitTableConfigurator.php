<?php

namespace App\Tables;

use App\Models\AgentAddCreditLimit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Gate;


class CreditLimitTableConfigurator extends AbstractTable
{

    /**
     * Create a new instance.
     *
     * @return void
     */
    public function __construct() {}

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

    // Define global search across multiple fields
    $globalSearch = AllowedFilter::callback('global', function ($query, $value) {
        $query->where(function ($query) use ($value) {
            foreach ((array)$value as $searchTerm) {
                // Search for fields in the current model's table
                $query->orWhere('id', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('created_at', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('amount', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('currency', 'LIKE', "%{$searchTerm}%");
    
                // Search related user model (first_name, last_name)
                $query->orWhereHas('user', function ($q) use ($searchTerm) {
                    $q->where('first_name', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('last_name', 'LIKE', "%{$searchTerm}%");
                });
    
                // Search related agent model (agent_code)
                $query->orWhereHas('agent', function ($q) use ($searchTerm) {
                    $q->where('agent_code', 'LIKE', "%{$searchTerm}%");
                });
    
                // Search active field for string "active" and numeric values (1 and 0)
                $query->orWhere(function ($q) use ($searchTerm) {
                    $searchTermLower = strtolower($searchTerm);  // Convert the search term to lowercase
                
                    if ($searchTermLower == 'active') {
                        $q->where('active', 1);  // If "active" is entered, search for active (1)
                    } elseif ($searchTermLower == 'inactive') {
                        $q->where('active', 0);  // If "inactive" is entered, search for inactive (0)
                    } else {
                        // If neither "active" nor "inactive", treat it as a general search term for "active"
                        $q->where('active', 'LIKE', "%{$searchTerm}%");
                    }
                });
                
            }
        });
    });
    
    

    return QueryBuilder::for(AgentAddCreditLimit::query()
        ->with(['user', 'agent'])) // Include relationships
        ->allowedFilters([
            AllowedFilter::exact('id'),
            AllowedFilter::exact('user_id'),
            AllowedFilter::exact('agent_id'),
            AllowedFilter::exact('currency'),
            AllowedFilter::exact('created_at'),
            AllowedFilter::exact('active'),
            $globalSearch,
        ])
        ->allowedSorts(['created_at', 'id', 'currency', 'amount']);
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
             ->column(key: 'id', label: 'ID', searchable: true, sortable: true)
     
             // Show First Name & Last Name instead of user_id
             ->column(key: 'user_id', label: 'Added By', as: function ($column, $model) {
                 return $model->user ? $model->user->first_name . ' ' . $model->user->last_name : 'N/A';
             }, searchable: true)
     
             // Show Agent Code instead of agent_id
             ->column(key: 'agent_id', label: 'Agent Ref', as: function ($column, $model) {
                 return $model->agent ? $model->agent->agent_code : 'N/A';
             }, searchable: true)
     
             ->column(key: 'amount', label: 'Amount', searchable: true, sortable: true, canBeHidden: true)
             ->column(key: 'currency', label: 'Currency', searchable: true, sortable: true, canBeHidden: true)
             ->column(key: 'created_at', label: 'Added At', searchable: true, sortable: true)
     
             // Approval Status Column
             ->column(key: 'active', label: 'Status', as: function ($column, $model) {
                 return $model->active ? 'Active' : 'Inactive';
             })
     
             // Pagination
             ->paginate(15)
     
             // Enable Export
             ->export(filename: 'credit_limit.xlsx');
     }
     
}
