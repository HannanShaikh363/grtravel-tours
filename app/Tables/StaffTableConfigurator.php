<?php

namespace App\Tables;

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


class StaffTableConfigurator extends AbstractTable
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
    // Fetch all agent codes for admin users
    $adminAgentCodes = User::where('type', 'admin')->pluck('agent_code')->toArray();

    // Define global search across multiple fields
    $globalSearch = AllowedFilter::callback('global', function ($query, $value) {
        $query->where(function ($query) use ($value) {
            foreach ((array)$value as $searchTerm) {
                $query
                    ->orWhere('first_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('id', 'LIKE', "%{$searchTerm}%");
            }
        });
    });

    return QueryBuilder::for(User::query()
        ->where('type', 'staff') // Only fetch staff users
        ->whereIn('agent_code', $adminAgentCodes) // Exclude agent codes matching admins
        ->with(['financeContact', 'company'])) // Include relationships
        ->orderByDesc('created_at')
        ->allowedFilters([
            AllowedFilter::exact('first_name'),
            AllowedFilter::exact('email'),
            AllowedFilter::exact('last_name'),
            AllowedFilter::exact('id'),
            $globalSearch,
        ])
        ->allowedSorts(['first_name', 'email', 'last_name', 'id']);
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
            ->defaultSort('first_name')
            ->column(key: 'first_name', searchable: true, sortable: true)
            ->column(key: 'last_name', searchable: true, sortable: true)
            ->column(key: 'email', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'email_verified_at', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'credit_limit', searchable: true, sortable: true)
            ->column(key: 'percentage_discount_surcharge', searchable: true, sortable: true)
            ->column(key: 'company.agent_name', label: 'Company Name', canBeHidden: true)
            ->column(key: 'company.country.name', label: 'Country', canBeHidden: true)
            ->column(key: 'company.city.name', label: 'City', canBeHidden: true)

            ->column(key: 'credit_limit', label: 'Current Credit Limit', exportAs: false, as: function ($column, $model) {
                return new HtmlString($model->credit_limit_currency . ' ' . $model->credit_limit  . ' ');
            })
            ->column(key: 'percentage_discount_surcharge', label: 'Percentage Discount/Surcharge', exportAs: false, as: function ($column, $model) {


                if ($model->type == 'agent') {
                    $htmlAdjustment = '';
                    $currentTime = \Illuminate\Support\Carbon::now()->format('Y-m-d H:i:s');
                    $adjustments = AgentPricingAdjustment::where('agent_id', $model->id)
                        ->where('active', 1)
                        ->where('effective_date', '<', $currentTime)
                        ->where('expiration_date', '>', $currentTime)
                        ->get();
                    $htmlAdjustment = $adjustments->map(function ($adjustment) {
                        $type = strtoupper($adjustment->transaction_type);
                        $action = $adjustment->percentage_type === 'surcharge' ? 'Surcharge' : 'Discount';
                        return "\n{$type}: {$action}: {$adjustment->percentage}%";
                    })->implode(' ');

                    return new HtmlString($htmlAdjustment);
                }
            })
            // Approval Status Column
            ->column(key: 'approved', label: 'Approval Status', as: function ($column, $model) {
                return $model->approved ? 'Approved' : 'Not Approved';
            })
            ->column(key: 'email_verified_at', label: 'Email Verify', as: function ($column, $model) {
                return $model->email_verified_at ? 'Verified' : 'Not Verify';
            })
            // Actions Column: Approve, Reject, Edit (Pencil Icon)
            ->column(key: 'actions', label: 'Actions', exportAs: false, as: function ($column, $model) {
                // Approve button
                $approveForm = '<form action="' . route('staff.approve', $model->id) . '" method="POST" class="inline">';
                $approveForm .= csrf_field();
                $approveForm .= '<button type="submit" class="btn bg-transparent px-2 border-none">
                     <svg data-tooltip-target="tooltip-approve" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="green" class="bi bi-check w-6 h-6 text-green-600" viewBox="0 0 16 16">
                         <path d="M13.485 1.929a.75.75 0 0 1 1.06 1.06l-8 8a.75.75 0 0 1-1.06 0l-4-4a.75.75 0 1 1 1.06-1.06L6 9.44l7.485-7.485z"/>
                     </svg>
                 </button>
                 <div id="tooltip-approve" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                    Approve
                    <div class="tooltip-arrow" data-popper-arrow></div>
                </div>';
                $approveForm .= '</form>';

                // Reject button
                $rejectForm = '<form action="' . route('staff.unapprove', $model->id) . '" method="POST" class="inline">';
                $rejectForm .= csrf_field();
                $rejectForm .= '<button type="submit" class="btn bg-transparent px-2 border-none">
                     <svg data-tooltip-target="tooltip-default" class="w-6 h-6 text-red-600" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                         <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 9-6 6m0-6 6 6m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                     </svg>
                 </button>
                 <div id="tooltip-default" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                    Reject
                    <div class="tooltip-arrow" data-popper-arrow></div>
                </div>';
                $rejectForm .= '</form>';

                // Edit (Pencil) button
                $editButton = '<a href="' . route('staff.edit', $model->id) . '" class="btn bg-transparent px-2 border-none">
                     <svg data-tooltip-target="tooltip-edit" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-pencil w-6 h-6 text-blue-600" viewBox="0 0 16 16">
                         <path d="M12.146.854a.5.5 0 0 1 .708 0l2.292 2.292a.5.5 0 0 1 0 .708l-9 9a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l9-9zM11.207 3l-8 8L2.5 13.5l2.5-.707 8-8L11.207 3zM13 2.207L12.207 1.5 14 1.5 14 3l-.793-.793L13 2.207z"/>
                     </svg>
                 </a>
                 <div id="tooltip-edit" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                    Edit
                    <div class="tooltip-arrow" data-popper-arrow></div>
                </div>';
                 if (Gate::allows('update staff')) {
                    // Return Approve, Reject, and Edit buttons
                     return new HtmlString($approveForm . ' ' . $rejectForm . ' ' . $editButton);
                }

                // Return Approve, Reject, and Edit buttons
            })

            // Pagination
            ->paginate(15)

            // Enable Export
            ->export();
    }
}
