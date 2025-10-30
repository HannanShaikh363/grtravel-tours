<?php

namespace App\Tables;

use App\Models\DiscountVoucher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\Facades\Toast;
use ProtoneMedia\Splade\SpladeTable;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class DiscountVoucherTableConfigurator extends AbstractTable
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
                        ->orwhere('code', 'LIKE', "%{$value}%")
                        ->orwhere('type', 'LIKE', "%{$value}%")
                        ->orWhere('value', 'LIKE', "%{$value}%")
                        ->orWhere('valid_from', 'LIKE', "%{$value}%")
                        ->orWhere('valid_until', 'LIKE', "%{$value}%");
                });
            });
        });

        // Get the authenticated user
        $user = auth()->user();

        // Start building the query
        $query = DiscountVoucher::query()->orderBy('created_at', 'desc');

        // Filter based on user type
        // if ($user->type === 'agent') {

        //     $query->where('bookings.user_id', $user->id); // Only get bookings for this agent
        // }

        return QueryBuilder::for($query)
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::exact('code'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('value'),
                AllowedFilter::exact('min_booking_amount'),
                AllowedFilter::exact('max_discount_amount'),
                AllowedFilter::exact('usage_limit'),
                AllowedFilter::exact('used_count'),
                AllowedFilter::exact('per_user_limit'),
                AllowedFilter::exact('valid_from'),
                AllowedFilter::exact('valid_until'),
                AllowedFilter::exact('applicable_to'),
                AllowedFilter::exact('is_public'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('created_at'),
                $globalSearch,
            ])
            ->allowedSorts(['id', 'code', 'type', 'value', 'min_booking_amount', 'max_discount_amount', 'usage_limit', 'used_count', 'per_user_limit', 'applicable_to', 'valid_from', 'created_at', 'valid_until', 'is_public', 'status']);
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
            ->column(key: 'code', label: 'Voucher Code', searchable: true, sortable: true)
            ->column(key: 'type', label: 'Voucher Type', searchable: true, sortable: true)
            ->column(key: 'value', label: 'Value', searchable: true, sortable: true)
            ->column(key: 'min_booking_amount', label: 'Min Booking Amount', searchable: true, sortable: true)
            ->column(key: 'max_discount_amount', label: 'Max Discount Cap', searchable: true, sortable: true)
            ->column(key: 'usage_limit', label: 'Usage Limit', searchable: true, sortable: true)
            ->column(key: 'used_count', label: 'Used Count', searchable: true, sortable: true)
            ->column(key: 'per_user_limit', label: 'Per User Limit', searchable: true, sortable: true)
            ->column(
                key: 'applicable_to',
                label: 'Applicable To',
                searchable: false,
                sortable: false,
                as: function ($column, $model) {
                    return is_array($model->applicable_to)
                        ? implode(', ', $model->applicable_to)
                        : '-';
                }
            )

            ->column(key: 'is_public', label: 'Is Public', searchable: true, sortable: true)
            ->column(key: 'status', label: 'Status', searchable: true, sortable: true)
            ->column(
                key: 'valid_from',
                label: 'Valid From',
                searchable: true,
                sortable: true,

                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return $model->valid_from;
                }
            )
            ->column(
                key: 'valid_until',
                label: 'Valid Until',
                searchable: true,
                sortable: true,

                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return $model->valid_until;
                }
            )
            ->column(
                key: 'created_at',
                label: 'Voucher Created At',
                searchable: true,
                sortable: true,

                as: function ($column, $model) {
                    // Access the actual created_at value from the model and convert it
                    return convertToUserTimeZone($model->created_at);
                }
            )

            // Actions Column
            ->column(
                key: 'actions',
                label: 'Actions',
                exportAs: false,
                as: function ($column, $model) {
                    $uniqueId = 'voucher_' . $model->id;

                    $assignButton = '
            <a href="' . route('voucher_user.create', ['voucher_id' => $model->id]) . '" class="inline mt-2">
                <svg data-tooltip-target="tooltip-assign-' . $uniqueId . '" class="w-6 h-6 text-gray-600 dark:text-white" fill="currentColor" viewBox="0 0 24 24">
                    <path fill-rule="evenodd" d="M9 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm-2 9a4 4 0 0 0-4 4v1a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-1a4 4 0 0 0-4-4H7Zm8-1a1 1 0 0 1 1-1h1v-1a1 1 0 1 1 2 0v1h1a1 1 0 1 1 0 2h-1v1a1 1 0 1 1-2 0v-1h-1a1 1 0 0 1-1-1Z" clip-rule="evenodd"/>
                </svg>
            </a>
            <div id="tooltip-assign-' . $uniqueId . '" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                Assign To
                <div class="tooltip-arrow" data-popper-arrow></div>
            </div>';

                    $editButton = '
            <a href="' . route('discount_voucher.edit', ['voucher_id' => $model->id]) . '" class="inline px-2">
                <svg data-tooltip-target="tooltip-view-' . $uniqueId . '" class="mt-6 w-6 h-6 text-gray-600 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                <path fill-rule="evenodd" d="M11.32 6.176H5c-1.105 0-2 .949-2 2.118v10.588C3 20.052 3.895 21 5 21h11c1.105 0 2-.948 2-2.118v-7.75l-3.914 4.144A2.46 2.46 0 0 1 12.81 16l-2.681.568c-1.75.37-3.292-1.263-2.942-3.115l.536-2.839c.097-.512.335-.983.684-1.352l2.914-3.086Z" clip-rule="evenodd"/>
                <path fill-rule="evenodd" d="M19.846 4.318a2.148 2.148 0 0 0-.437-.692 2.014 2.014 0 0 0-.654-.463 1.92 1.92 0 0 0-1.544 0 2.014 2.014 0 0 0-.654.463l-.546.578 2.852 3.02.546-.579a2.14 2.14 0 0 0 .437-.692 2.244 2.244 0 0 0 0-1.635ZM17.45 8.721 14.597 5.7 9.82 10.76a.54.54 0 0 0-.137.27l-.536 2.84c-.07.37.239.696.588.622l2.682-.567a.492.492 0 0 0 .255-.145l4.778-5.06Z" clip-rule="evenodd"/>
                </svg>

            </a>
            <div id="tooltip-view-' . $uniqueId . '" role="tooltip" class="absolute z-10 invisible inline-block px-2 text-sm font-medium text-white transition-opacity duration-300 bg-gray-900 rounded-xl shadow-xl opacity-0 tooltip dark:bg-gray-700">
                Edit
                <div class="tooltip-arrow" data-popper-arrow></div>
            </div>';

                    return new HtmlString($assignButton . $editButton);
                }
            )



            // Pagination
            ->paginate(15)

            // Bulk Action: Delete
            ->column('id', sortable: true, hidden: true)
            ->bulkAction(
                label: 'Delete',
                each: fn(DiscountVoucher $bookings) => $this->deleteVouchers($bookings),
                before: fn() => info('Deleting the selected voucher'),
                // after: fn() => Toast::info('Booking(s) have been deleted!'),
                confirm: 'Deleting Voucher Data?',
                confirmText: 'Are you sure you want to delete the voucher data?',
                confirmButton: 'Yes, Delete Selected Row(s)!',
                cancelButton: 'No, Do Not Delete!',
            )

            // Enable Export
            ->export();
    }

    public function deleteVouchers(DiscountVoucher $bookings)
    {
        // Check if the user has permission to delete
        if (Gate::denies('delete discountVouchers')) {
            // Optionally, show error message
            Toast::info('You do not have permission to delete vouchers.')->autoDismiss(3);

            return;
        }

        // Proceed with the delete operation if the user has permission
        $bookings->delete();

        // Show success message after deletion
        Toast::info('voucher has been deleted!')->autoDismiss(3);
    }
}
