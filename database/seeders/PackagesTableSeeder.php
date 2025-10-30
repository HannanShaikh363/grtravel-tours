<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PackagesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('packages')->insert([
            [
                'package_name' => 'ECONOMY (SEDAN CAR)',
                'image' => 'ECONOMY (SEDAN CAR).jpg',
                'max_allowed_passenger' => 3,
                'max_allowed_luggage' => 2,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'package_name' => 'STANDARD (MPV 7 SEATER)',
                'image' => 'STANDARD (MPV 7 SEATER).jpg',
                'max_allowed_passenger' => 6,
                'max_allowed_luggage' => 4,
                'status' => 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'package_name' => 'STANDARD (SUV 7 SEATER)',
                'image' => 'STANDARD (SUV 7 SEATER).jpg',
                'max_allowed_passenger' => 2,
                'max_allowed_luggage' => 3,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'package_name' => 'LUXURY CAR',
                'image' => 'LUXURY CAR.jpg',
                'max_allowed_passenger' => 4,
                'max_allowed_luggage' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'package_name' => '12 SEATER MINI VAN',
                'image' => '12 SEATER MINI VAN.jpg',
                'max_allowed_passenger' => 12,
                'max_allowed_luggage' => 3,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'package_name' => '14 SEATER VAN',
                'image' => '14 SEATER VAN.jpg',
                'max_allowed_passenger' => 14,
                'max_allowed_luggage' => 3,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'package_name' => '16 SEATER VAN',
                'image' => '16 SEATER VAN.jpg',
                'max_allowed_passenger' => 16,
                'max_allowed_luggage' => 3,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'package_name' => '44 SEATER COACH',
                'image' => '44 SEATER COACH.jpg',
                'max_allowed_passenger' => 44,
                'max_allowed_luggage' => 20,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}
