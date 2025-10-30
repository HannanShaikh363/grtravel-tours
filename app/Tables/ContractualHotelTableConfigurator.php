<?php
namespace App\Tables;
use App\Models\ContractualHotel;
use Illuminate\Http\Request;
use ProtoneMedia\Splade\AbstractTable;
use ProtoneMedia\Splade\SpladeTable;
use Illuminate\Support\Facades\Gate;
use ProtoneMedia\Splade\Facades\Toast;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class ContractualHotelTableConfigurator extends AbstractTable
{
    public function __construct()
    {
        //
    }

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
                ->orWhere('hotel_name', 'LIKE', "%{$value}%")
                ->orWhere('currency', 'LIKE', "%{$value}%")
                ->orWhereHas('cityRelation', function ($q) use ($value) {
                    $q->where('name', 'LIKE', "%{$value}%"); // search by city name
                })
                ->orWhereHas('countryRelation', function ($q) use ($value) {
                    $q->where('name', 'LIKE', "%{$value}%"); // search by city name
                });
        });
    });
});


        return QueryBuilder::for(
            ContractualHotel::query()->with('cityRelation')->orderByDesc('created_at')
        )

            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::partial('hotel_name'),
                AllowedFilter::partial('city'),
                AllowedFilter::partial('country'),
                AllowedFilter::partial('currency'),
                $globalSearch,
            ])
            ->allowedSorts([
                'id',
                'hotel_name',
                'currency',
                'created_at',
            ]);
    }


   public function configure(SpladeTable $table)
    {
        $table
            ->withGlobalSearch()
            ->column(key: 'id', label: 'ID', sortable: true, searchable: true)
            ->column(key: 'hotel_name', label: 'Hotel Name')
            ->column(key: 'cityRelation.name', label: 'City') // This one uses relationship
            ->column(key: 'countryRelation.name', label: 'Country') // This one uses relationship
            ->column(key: 'description', label: 'Description', as: function ($column, $model) {
                return Str::limit(strip_tags($model->description), 50, '...');
            })

            ->column(key: 'property_amenities', label: 'Amenities', as: function ($column, $model) {
                return Str::limit(strip_tags($model->property_amenities), 50, '...');
            })

            ->column(key: 'room_features', label: 'Room Features', as: function ($column, $model) {
                return Str::limit(strip_tags($model->room_features), 50, '...');
            })

           
            ->column(key: 'room_types', label: 'Room Types', as: function ($column, $model) {
                return Str::limit(strip_tags($model->room_types), 50, '...');
            })
            ->column(key: 'important_info', label: 'Important Info', as: function ($column, $model) {
                return Str::limit(strip_tags($model->important_info), 50, '...');
            })
            ->column(key: 'extra_bed_adult', label: 'Extra Bed (Adult)')
            ->column(key: 'extra_bed_child', label: 'Extra Bed (Child)')
            ->column(key: 'currency', label: 'Currency')

            ->column(key: 'created_at', label: 'Created At', sortable: true, canBeHidden: true, hidden: true, as: function ($column, $model) {
                return convertToUserTimeZone($model->created_at);
            })
            ->column(key: 'updated_at', label: 'Updated At', sortable: true, canBeHidden: true, hidden: true, as: function ($column, $model) {
                return convertToUserTimeZone($model->updated_at);
            })
            ->column(key: 'actions', label: 'Actions', exportAs: false, as: function ($column, $model) {
                $action = route('contractual_hotel.edit', $model->id); // Ensure route exists
                $slot = 'Update';
                return view('table.component.actions', compact('model', 'action', 'slot'));
            })
            ->paginate(15)
            ->bulkAction(
                label: 'Delete',
                each: fn(ContractualHotel $hotel) => $this->deleteHotel($hotel),
                confirm: 'Are you sure you want to delete the selected hotels?',
                confirmText: 'This action cannot be undone.',
                confirmButton: 'Yes, delete',
                cancelButton: 'Cancel',
            )
            ->export();
    }


    public function deleteHotel(ContractualHotel $hotel)
    {
        // if (Gate::denies('delete contractual')) {
            // Toast::info('You do not have permission to delete this hotel.')->autoDismiss(3);
            // return;
        // }

        $hotel->delete();
        Toast::info('Hotel deleted successfully!')->autoDismiss(3);
    }
}
