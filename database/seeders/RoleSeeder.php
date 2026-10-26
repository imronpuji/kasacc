<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['approve-payments', 'request-payments', 'post-sales', 'lock-periods', 'view-reports'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        Role::findOrCreate('owner', 'web')->syncPermissions(['approve-payments', 'request-payments', 'lock-periods', 'view-reports', 'post-sales']);
        Role::findOrCreate('approver', 'web')->syncPermissions(['approve-payments', 'view-reports']);
        Role::findOrCreate('finance_admin', 'web')->syncPermissions(['request-payments', 'view-reports']);
        Role::findOrCreate('cashier', 'web')->syncPermissions(['post-sales']);
    }
}
