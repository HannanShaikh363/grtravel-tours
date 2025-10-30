<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class MainAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'shahzaib.ahmed@mmcgbl.com',
            'username' => 'admin',
            'type' => 'admin',
            'phone' => '999999999',
            'password' => Hash::make('click123'),
            'email_verified_at' => Carbon::now(),

        ]);
        $user->assignRole('admin');
        $user->givePermissionTo(Permission::all());
        $user = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'director@grtravel.net',
            'username' => 'grtravel',
            'type' => 'admin',
            'phone' => '+60166065544',
            'password' => Hash::make('click123'),
            'email_verified_at' => Carbon::now(),

        ]);
        $user->assignRole('admin');
        $user->givePermissionTo(Permission::all());

    }
}
