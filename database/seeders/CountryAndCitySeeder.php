<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CountryAndCitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Path to your SQL file
        $path = database_path('countries.sql');
        // Load the SQL file content
        $sql = File::get($path);
        // Execute the SQL queries
        DB::unprepared($sql);
        $this->command->info('SQL file imported successfully!');
        // Path to your SQL file
        $path = database_path('cities.sql');
        // Load the SQL file content
        $sql = File::get($path);
        // Execute the SQL queries
        DB::unprepared($sql);
        $this->command->info('SQL file imported successfully!');

    }
}
