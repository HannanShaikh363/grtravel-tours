<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateGentingRoomData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:genting-room-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate old genting room details to new room and passenger tables';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $groupedRooms = DB::table('old_genting_room_details')
            ->get()
            ->groupBy(function ($item) {
                return $item->booking_id . '_' . $item->room_no;
            });

        foreach ($groupedRooms as $groupKey => $passengers) {
            $first = $passengers->first();

            // Collect child ages from all rows in this room group
            $childAges = $passengers->pluck('child_ages')
                ->filter() // remove nulls
                ->flatMap(function ($ageJson) {
                    return json_decode($ageJson, true) ?? [];
                })
                ->values()
                ->all();


            $roomId = DB::table('genting_room_details')->insertGetId([
                'room_no' => $first->room_no,
                'booking_id' => $first->booking_id,
                'number_of_adults' => $passengers->sum('number_of_adults'),
                'number_of_children' => $passengers->sum('number_of_children'),
                'child_ages' => !empty($childAges) ? json_encode($childAges) : null,
                'extra_bed_for_child' => $passengers->contains(function ($p) {
                    return $p->extra_bed_for_child;
                }),
                'created_at' => $first->created_at,
                'updated_at' => $first->updated_at,
            ]);

            foreach ($passengers as $passenger) {
                DB::table('genting_room_passenger_details')->insert([
                    'room_detail_id' => $roomId,
                    'passenger_title' => $passenger->passenger_title,
                    'passenger_full_name' => $passenger->passenger_full_name,
                    'phone_code' => $passenger->phone_code,
                    'passenger_contact_number' => $passenger->passenger_contact_number,
                    'passenger_email_address' => $passenger->passenger_email_address,
                    'nationality_id' => $passenger->nationality_id,
                    'created_at' => $passenger->created_at,
                    'updated_at' => $passenger->updated_at,
                ]);
            }
        }


        $this->info("Data migrated successfully!");
    }
}
