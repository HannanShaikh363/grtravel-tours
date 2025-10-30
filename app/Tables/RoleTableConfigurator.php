<?php

namespace App\Tables;

use Spatie\Permission\Models\Role;
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

class RoleTableConfigurator extends AbstractTable
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
        $globalSearch = AllowedFilter::callback('global', function ($query, $value) {
            $query->where(function ($query) use ($value) {
                Collection::wrap($value)->each(function ($value) use ($query) {
                    $query
                        ->orWhere('name', 'LIKE', "%{$value}%")
                        ->orWhere('guard_name', 'LIKE', "%{$value}%")
                        ->orWhere('id', 'LIKE', "%{$value}%");
                });
            });
        });

        return QueryBuilder::for(Role::query())
            ->allowedFilters([
                AllowedFilter::exact('name'),
                AllowedFilter::exact('guard_name'),
                AllowedFilter::exact('id'),
                $globalSearch,
            ])
            ->allowedSorts(['name', 'guard_name', 'id']); // Use alias for sorting
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
            ->column(key: 'name', searchable: true, sortable: true)
            ->defaultSort('name')
            ->column(key: 'guard_name', searchable: true, sortable: true)
            // Actions Column: Approve, Reject, Edit (Pencil Icon)
            ->column(key: 'actions', label: 'Actions', exportAs: false, as: function ($column, $model) {

                // Edit (Pencil) button
                $editButton = '<a href="' . route('role.edit', $model->id) . '" class="btn bg-transparent px-2 border-none">
                     <svg data-tooltip-target="tooltip-edit" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-pencil w-6 h-6 text-blue-600" viewBox="0 0 16 16">
                         <path d="M12.146.854a.5.5 0 0 1 .708 0l2.292 2.292a.5.5 0 0 1 0 .708l-9 9a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l9-9zM11.207 3l-8 8L2.5 13.5l2.5-.707 8-8L11.207 3zM13 2.207L12.207 1.5 14 1.5 14 3l-.793-.793L13 2.207z"/>
                     </svg>
                 </a>
                 <div id="tooltip-edit" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                    Edit
                    <div class="tooltip-arrow" data-popper-arrow></div>
                </div>';
                if (Gate::allows('update role')) {
                    // Return Approve, Reject, and Edit buttons
                    return new HtmlString( $editButton);
                }
            })

            // Pagination
            ->paginate(15)

            // Enable Export
            ->export();
    }
}
