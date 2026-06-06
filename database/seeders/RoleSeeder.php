<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = Role::withTrashed()->firstOrNew(['slug' => 'super-admin']);

        $role->fill([
            'name' => 'مدیر کل',
            'guard_name' => 'web',
            'description' => 'مالک سامانه با دسترسی کامل به همه بخش‌های مدیریتی.',
        ]);

        if ($role->trashed()) {
            $role->restore();
        }

        $role->save();

        $role->permissions()->sync(
            Permission::query()->pluck('id')->all()
        );
    }
}
