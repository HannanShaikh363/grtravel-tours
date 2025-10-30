<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class GentingPackagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('genting_packages')->insert([
            [
                'package' => '2 DAYS 1 NIGHT BED & BREAKFAST PACKAGE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'package' => '3 DAYS 2 NIGHT GENTING SKYWORLDS PACKAGE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
