<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $role = Role::firstOrCreate(['name' => 'admin']);
        // create permissions
        Permission::firstOrCreate(['name' => 'list agent']);
        Permission::firstOrCreate(['name' => 'delete agent']);
        Permission::firstOrCreate(['name' => 'create agent']);
        Permission::firstOrCreate(['name' => 'update agent']);

        Permission::firstOrCreate(['name' => 'list booking']);
        Permission::firstOrCreate(['name' => 'delete booking']);
        Permission::firstOrCreate(['name' => 'create booking']);
        Permission::firstOrCreate(['name' => 'update booking']);

        Permission::firstOrCreate(['name' => 'list rate']);
        Permission::firstOrCreate(['name' => 'delete rate']);
        Permission::firstOrCreate(['name' => 'create rate']);
        Permission::firstOrCreate(['name' => 'update rate']);

        Permission::firstOrCreate(['name' => 'list location']);
        Permission::firstOrCreate(['name' => 'delete location']);
        Permission::firstOrCreate(['name' => 'create location']);
        Permission::firstOrCreate(['name' => 'update location']);


        Permission::firstOrCreate(['name' => 'list transport']);
        Permission::firstOrCreate(['name' => 'delete transport']);
        Permission::firstOrCreate(['name' => 'create transport']);
        Permission::firstOrCreate(['name' => 'update transport']);


        Permission::firstOrCreate(['name' => 'list tour']);
        Permission::firstOrCreate(['name' => 'delete tour']);
        Permission::firstOrCreate(['name' => 'create tour']);
        Permission::firstOrCreate(['name' => 'update tour']);

        Permission::firstOrCreate(['name' => 'list genting']);
        Permission::firstOrCreate(['name' => 'delete genting']);
        Permission::firstOrCreate(['name' => 'create genting']);
        Permission::firstOrCreate(['name' => 'update genting']);


        Permission::firstOrCreate(['name' => 'list staff']);
        Permission::firstOrCreate(['name' => 'delete staff']);
        Permission::firstOrCreate(['name' => 'create staff']);
        Permission::firstOrCreate(['name' => 'update staff']);
        Permission::firstOrCreate(['name' => 'staff permission']);

        Permission::firstOrCreate(['name' => 'list role']);
        Permission::firstOrCreate(['name' => 'delete role']);
        Permission::firstOrCreate(['name' => 'create role']);
        Permission::firstOrCreate(['name' => 'update role']);

        Permission::firstOrCreate(['name' => 'list account']);
        Permission::firstOrCreate(['name' => 'delete account']);
        Permission::firstOrCreate(['name' => 'create account']);
        Permission::firstOrCreate(['name' => 'update account']);

        Permission::firstOrCreate(['name' => 'list hotel']);
        Permission::firstOrCreate(['name' => 'delete hotel']);
        Permission::firstOrCreate(['name' => 'create hotel']);
        Permission::firstOrCreate(['name' => 'update hotel']);

        Permission::firstOrCreate(['name' => 'list settings']);
        Permission::firstOrCreate(['name' => 'delete settings']);
        Permission::firstOrCreate(['name' => 'create settings']);
        Permission::firstOrCreate(['name' => 'update settings']);

        Permission::firstOrCreate(['name' => 'list currencyRates']);
        Permission::firstOrCreate(['name' => 'delete currencyRates']);
        Permission::firstOrCreate(['name' => 'create currencyRates']);
        Permission::firstOrCreate(['name' => 'update currencyRates']);

        Permission::firstOrCreate(['name' => 'list discountVouchers']);
        Permission::firstOrCreate(['name' => 'delete discountVouchers']);
        Permission::firstOrCreate(['name' => 'create discountVouchers']);
        Permission::firstOrCreate(['name' => 'update discountVouchers']);


        Permission::firstOrCreate(['name' => 'list discountVoucherUser']);
        Permission::firstOrCreate(['name' => 'delete discountVoucherUser']);
        Permission::firstOrCreate(['name' => 'create discountVoucherUser']);
        Permission::firstOrCreate(['name' => 'update discountVoucherUser']);
        
        // create roles and assign created permissions

        // this can be done as separate statements

        $role->givePermissionTo('update agent');
        $role->givePermissionTo(Permission::all());
    }
}
