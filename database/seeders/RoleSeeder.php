<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['approve-payments', 'request-payments', 'post-sales', 'post-purchases', 'manage-services', 'lock-periods', 'view-reports', 'manage-hr', 'approve-payroll', 'pay-payroll', 'reconcile-bank', 'void-sales'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        Role::findOrCreate('owner', 'web')->syncPermissions(['approve-payments', 'request-payments', 'lock-periods', 'view-reports', 'post-sales', 'post-purchases', 'manage-services', 'manage-hr', 'approve-payroll', 'pay-payroll', 'reconcile-bank', 'void-sales']);
        Role::findOrCreate('approver', 'web')->syncPermissions(['approve-payments', 'view-reports', 'approve-payroll', 'void-sales']);
        Role::findOrCreate('finance_admin', 'web')->syncPermissions(['request-payments', 'view-reports', 'post-purchases', 'manage-hr', 'pay-payroll', 'reconcile-bank']);
        Role::findOrCreate('hr', 'web')->syncPermissions(['manage-hr']);
        Role::findOrCreate('cashier', 'web')->syncPermissions(['post-sales', 'manage-services']);
        Role::findOrCreate('technician', 'web')->syncPermissions(['manage-services']);
        Role::findOrCreate('staff', 'web')->syncPermissions([]); // karyawan umum: hanya Absen saya
    }
}
