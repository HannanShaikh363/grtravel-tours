<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class AmenitiesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $propertyAmenities = [
            'Secured parking', 'Paid internet', 'Fitness Center with Gym / Workout Room', 'Bar / lounge',
            'Casino and Gambling', 'Children Activities (Kid / Family Friendly)', 'Business Center with Internet Access',
            'Conference facilities', 'Coffee shop', 'Restaurant', 'Breakfast buffet', 'Banquet room', 'Meeting rooms',
            'Salon', '24-hour security', 'Baggage storage', 'Convenience store', 'Shops', 'ATM on site',
            '24-hour check-in', '24-hour front desk', 'Express check-in / check-out', 'Dry cleaning', 'Valet parking',
            'Public wifi', 'Casino and Gambling', 'Car hire', 'Business Center with Internet Access', 'Meeting rooms',
            'Massage', 'Taxi service', 'Steam room', 'Concierge', 'Currency exchange', 'Laundry service'
        ];

        $roomFeatures = [
            'Housekeeping', 'Safe', 'Telephone', 'Flatscreen TV', 'Air conditioning',
            'Iron', 'Bath / shower', 'Complimentary toiletries', 'Hair dryer'
        ];

        $roomTypes = [
            'Mountain view', 'Family rooms', 'Smoking rooms available', 'Non-smoking rooms', 'Suites'
        ];

        $amenities = [];
        $timestamp = Carbon::now();


        foreach ($propertyAmenities as $amenity) {
            $amenities[] = ['name' => $amenity, 'type' => 'property', 'created_at' => $timestamp, 'updated_at' => $timestamp];
        }

        foreach ($roomFeatures as $feature) {
            $amenities[] = ['name' => $feature, 'type' => 'room_feature', 'created_at' => $timestamp, 'updated_at' => $timestamp];
        }

        foreach ($roomTypes as $type) {
            $amenities[] = ['name' => $type, 'type' => 'room_type', 'created_at' => $timestamp, 'updated_at' => $timestamp];
        }

        DB::table('amenities')->insert($amenities);
    }
}
