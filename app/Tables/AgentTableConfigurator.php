<?php

namespace App\Tables;

use App\Models\AgentPricingAdjustment;
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


class AgentTableConfigurator extends AbstractTable
{

    /**
     * Create a new instance.
     *
     * @return void
     */
    public function __construct()
    {
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
                        ->orWhere('first_name', 'LIKE', "%{$value}%")
                        ->orWhere('last_name', 'LIKE', "%{$value}%")
                        ->orWhere('email', 'LIKE', "%{$value}%")
                        ->orWhere('id', 'LIKE', "%{$value}%")
                        ->orWhere('agent_code', 'LIKE', "%{$value}%")
                        ->orWhere('email_verified_at', 'LIKE', "%{$value}%");
                });
            });
        });

        return QueryBuilder::for(User::query()
            ->where('type', 'agent')
            ->with('financeContact', 'company'))
            ->orderByDesc('created_at')
            ->allowedFilters([
                AllowedFilter::exact('first_name'),
                AllowedFilter::exact('email'),
                AllowedFilter::exact('last_name'),
                AllowedFilter::exact('id'),
                AllowedFilter::exact('agent_code'),
                AllowedFilter::exact('email_verified_at'),
                $globalSearch,
            ])
            ->allowedSorts(['first_name', 'email', 'last_name', 'id', 'agent_code', 'email_verified_at']); // Use alias for sorting
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
            ->column(key: 'Name', searchable: true, sortable: true, as: function ($column, $model) {
                return $model->first_name . ' ' . $model->last_name;
            })
            // ->column(key: 'last_name', searchable: true, sortable: true, canBeHidden: true, hidden: true)
            ->column(key: 'email', searchable: true, sortable: true, canBeHidden: true, hidden: true)
            ->column(key: 'email_verified_at', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'agent_code', searchable: true, sortable: true, canBeHidden: true)
            ->column(key: 'credit_limit', searchable: true, sortable: true)
            ->column(key: 'percentage_discount_surcharge', searchable: true, sortable: true)
            ->column(key: 'company.agent_name', label: 'Company Name', canBeHidden: true)
            ->column(key: 'company.country.name', label: 'Country', canBeHidden: true)
            ->column(key: 'company.city.name', label: 'City', canBeHidden: true, hidden: true)

            ->column(key: 'credit_limit', label: 'Current Credit Limit', exportAs: false, as: function ($column, $model) {
                return new HtmlString($model->credit_limit_currency . ' ' . $model->credit_limit . ' ');
            })
            ->column(key: 'percentage_discount_surcharge', label: 'Percentage Discount/Surcharge', exportAs: false, as: function ($column, $model) {


                if ($model->type == 'agent') {
                    $htmlAdjustment = '';
                    $currentTime = \Illuminate\Support\Carbon::now()->format('Y-m-d H:i:s');
                    $adjustments = AgentPricingAdjustment::where('agent_id', $model->id)
                        ->get();

                        $default = $adjustments->first(); // Get one item
                    // $htmlAdjustment = $adjustments->map(function ($adjustment) {
                    //     $type = strtoupper($adjustment->transaction_type);
                    //     $action = $adjustment->percentage_type === 'surcharge' ? 'Surcharge' : 'Discount';
                    //     return "\n{$type}: {$action}: {$adjustment->percentage}%";
                    // })->implode(' ');

                    return view('agent.partials.adjustment_edit',[
                        'model' => $model,
                        'adjustments' => $adjustments,
                        'default' => $default,
                    ]);
                }
            })
            // Approval Status Column
            ->column(key: 'approved', label: 'Approval Status', as: function ($column, $model) {
                return $model->approved ? 'Approved' : 'Not Approved';
            })
            ->column(key: 'email_verified_at', label: 'Email Verify', as: function ($column, $model) {
                return $model->email_verified_at ? 'Verified' : 'Not Verify';
            })
            ->column(key: 'company.certificate', label: 'Company Certificate', exportAs: false, as: function ($column, $model) {
                // Check if the model has a certificate
                if (isset($model->company) && isset($model->company['certificate']) && $model->company['certificate']) {
                    $downloadUrl = ltrim($model->company['certificate'], '/public');
                    // Generate the download button
                    $downloadButton = '<a href="' . asset($downloadUrl) . '" class="btn bg-transparent px-2 border-none mt-4" type="application/pdf" download="' . basename($model->certificate) . '">
                        <svg data-tooltip-target="tooltip-download" class="w-7 h-7 text-gray-500 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 13V4M7 14H5a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-2m-1-5-4 5-4-5m9 8h.01"/>
            </svg>
            
                    </a>
                    <div id="tooltip-download" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                        Download
                        <div class="tooltip-arrow" data-popper-arrow></div>
                    </div>';
                    return new HtmlString($downloadButton);
                }
                // Return empty string if no certificate is available
                return '';
            })


            // Actions Column: Approve, Reject, Edit (Pencil Icon)
            ->column(key: 'actions', label: 'Actions', exportAs: false, as: function ($column, $model) {
                // Approve button
                $approveForm = '<form action="' . route('agent.approve', $model->id) . '" method="POST" class="inline">';
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
                $rejectForm = '<form action="' . route('agent.unapprove', $model->id) . '" method="POST" class="inline">';
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
                $editButton = '<a href="' . route('agent.edit', $model->id) . '" class="btn bg-transparent px-2 border-none">
                     <svg data-tooltip-target="tooltip-edit" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-pencil w-6 h-6 text-blue-600" viewBox="0 0 16 16">
                         <path d="M12.146.854a.5.5 0 0 1 .708 0l2.292 2.292a.5.5 0 0 1 0 .708l-9 9a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l9-9zM11.207 3l-8 8L2.5 13.5l2.5-.707 8-8L11.207 3zM13 2.207L12.207 1.5 14 1.5 14 3l-.793-.793L13 2.207z"/>
                     </svg>
                 </a>
                 <div id="tooltip-edit" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                    Edit
                    <div class="tooltip-arrow" data-popper-arrow></div>
                </div>';

                // Login as Button
                $viewButton = '<a href="' . route('impersonate.start', $model->id) . '" class="btn bg-transparent mt-2 border-none">
                    <svg data-tooltip-target="tooltip-view" class="border-none" width="16px" height="16px" viewBox="0 0 24 30" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 16C8 18.8284 8 20.2426 8.87868 21.1213C9.75736 22 11.1716 22 14 22H15C17.8284 22 19.2426 22 20.1213 21.1213C21 20.2426 21 18.8284 21 16V8C21 5.17157 21 3.75736 20.1213 2.87868C19.2426 2 17.8284 2 15 2H14C11.1716 2 9.75736 2 8.87868 2.87868C8 3.75736 8 5.17157 8 8" stroke="#1C274C" stroke-width="1.5" stroke-linecap="round"/>
                    <path opacity="0.5" d="M8 19.5C5.64298 19.5 4.46447 19.5 3.73223 18.7678C3 18.0355 3 16.857 3 14.5V9.5C3 7.14298 3 5.96447 3.73223 5.23223C4.46447 4.5 5.64298 4.5 8 4.5" stroke="#1C274C" stroke-width="1.5"/>
                    <path d="M6 12L15 12M15 12L12.5 14.5M15 12L12.5 9.5" stroke="#1C274C" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    
                    </a>
                    <div id="tooltip-view" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                        Login As 
                        <div class="tooltip-arrow" data-popper-arrow></div>
                    </div>';


                // Return Approve, Reject, and Edit buttons
    
                if (Gate::allows('update agent')) {
                    // Approve, Reject, and Edit buttons
                    $buttons = $approveForm . ' ' . $rejectForm . ' ' . $editButton;

                    // Add Login button only if the user is admin
                    if (auth()->user()->type === 'admin' || auth()->user()->hasRole('admin')) {
                        $buttons .= ' ' . $viewButton; // Login button
                    }

                    return new HtmlString($buttons);
                } else {
                    // Only show Login button if the user is admin
                    if (auth()->user()->type === 'admin' || auth()->user()->hasRole('admin')) {
                        return new HtmlString($viewButton); // Login button
                    }

                    return new HtmlString(''); // No buttons for non-admin users
                }

            })

            // Pagination
            ->paginate(15)

            // Bulk Action: Delete
            // ->bulkAction(
            //     label: 'Delete',
            //     each: fn(User $agents) => $agents->delete(),
            //     before: fn() => info('Deleting the selected Agent'),
            //     after: fn() => Toast::info('Agent(s) have been deleted!'),
            //     confirm: 'Deleting Agent Data?',
            //     confirmText: 'Are you sure you want to delete the agent data?',
            //     confirmButton: 'Yes, Delete Selected Row(s)!',
            //     cancelButton: 'No, Do Not Delete!'
            // )

            // Enable Export
            ->export();
    }
}
