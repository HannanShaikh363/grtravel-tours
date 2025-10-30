<?php

namespace App\Tables;

use App\Models\ContractualHotelRate;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use Illuminate\Support\Facades\Gate;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;


class HotelRateTableConfigurator extends AbstractTable
{
    public function authorize(Request $request)
    {
        return true;
    }

    public function for()
    {
        $globalSearch = AllowedFilter::callback('global', function ($query, $value) {
            $query->where(function ($query) use ($value) {
                Collection::wrap($value)->each(function ($value) use ($query) {
                    $query
                        ->orWhere('contractual_hotel_rates.id', 'LIKE', "%{$value}%")
                        ->orWhere('contractual_hotels.hotel_name', 'LIKE', "%{$value}%")
                        ->orWhere('room_type', 'LIKE', "%{$value}%")
                        ->orWhere('weekdays_price', 'LIKE', "%{$value}%")
                        ->orWhere('weekend_price', 'LIKE', "%{$value}%")
                        ->orWhere('contractual_hotel_rates.currency', 'LIKE', "%{$value}%")
                        ->orWhere('entitlements', 'LIKE', "%{$value}%")
                        ->orWhere('no_of_beds', 'LIKE', "%{$value}%")
                        ->orWhere('room_capacity', 'LIKE', "%{$value}%");
                });
            });
        });

        $query = ContractualHotelRate::query()
            ->join('contractual_hotels', 'contractual_hotel_rates.hotel_id', '=', 'contractual_hotels.id')
            ->select(
                'contractual_hotel_rates.*',
                'contractual_hotels.hotel_name as hotelName'
            )
            ->orderByDesc('contractual_hotel_rates.created_at')
            ->with('contractualHotel');

        return QueryBuilder::for($query)
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::callback('hotelName', function ($query, $value) {
                    $query->whereHas('contractualHotel', function ($q) use ($value) {
                        $q->where('hotel_name', 'LIKE', "%{$value}%");
                    });
                }),
                AllowedFilter::exact('room_type'),
                AllowedFilter::exact('weekdays_price'),
                AllowedFilter::exact('weekend_price'),
                AllowedFilter::exact('currency'),
                AllowedFilter::exact('entitlements'),
                AllowedFilter::exact('no_of_beds'),
                AllowedFilter::exact('room_capacity'),
                AllowedFilter::exact('effective_date'),
                AllowedFilter::exact('expiry_date'),
                $globalSearch,
            ])
            ->allowedSorts([
                'id',
                'hotelName',
                'room_type',
                'weekdays_price',
                'weekend_price',
                'currency',
                'no_of_beds',
                'room_capacity',
                'effective_date',
                'expiry_date',
                'created_at',
                'updated_at',
            ]);
    }

    public function configure(SpladeTable $table)
    {
        $table
            ->withGlobalSearch()
            ->column('id', label: 'ID', sortable: true, searchable: true)
            ->column('hotelName', label: 'Hotel Name', sortable: true, searchable: true)
            ->column('room_type', label: 'Room Type', searchable: true)
            ->column('weekdays_price', label: 'Weekdays Price', sortable: true)
            ->column('weekend_price', label: 'Weekend Price', sortable: true)
            ->column('currency', label: 'Currency', sortable: false, searchable: true)
             ->column(key: 'entitlements', label: 'Entitlements', as: function ($column, $model) {
                return Str::limit(strip_tags($model->entitlements), 50, '...');
            })
            ->column('no_of_beds', label: 'No of Beds', sortable: true)
            ->column('room_capacity', label: 'Room Capacity', sortable: true)
            ->column('effective_date', sortable: true)
            ->column('expiry_date', sortable: true)
            ->column('created_at', label: 'Created at', searchable: false, sortable: true, canBeHidden: true, hidden: true,
                as: fn($column, $model) => convertToUserTimeZone($model->created_at)
            )
            ->column('updated_at', label: 'Updated at', searchable: false, sortable: true, canBeHidden: true, hidden: true,
                as: fn($column, $model) => convertToUserTimeZone($model->updated_at)
            )
            ->column('actions', label: 'Actions', exportAs: false, as: function ($column, $model) {
                if (Gate::allows('update genting')) {
                    $action = route('contractual_hotel.rates.edit', $model->id);
                    $slot = 'Update';
                    return view('table.component.actions', compact('model', 'action', 'slot'));
                }
            })
            ->paginate(15)
            ->column('id', sortable: false, exportAs: false)->bulkAction(
                label: 'Delete',
                each: fn(ContractualHotelRate $rate) => $this->deleteRate($rate),
                before: fn() => info('Deleting selected hotel rates'),
                confirm: 'Delete selected rates?',
                confirmText: 'Are you sure you want to delete the selected hotel rates?',
                confirmButton: 'Yes, delete!',
                cancelButton: 'Cancel'
            )
            ->export();
    }

    public function deleteRate(ContractualHotelRate $rate)
    {
        // if (Gate::denies('delete contractual')) {
        //     Toast::info('You do not have permission to delete this rate.')->autoDismiss(3);
        //     return;
        // }

        $rate->delete();

        Toast::info('Hotel rate deleted successfully.')->autoDismiss(3);
    }
}
