<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Users
            'user.all',
            'user.store',
            'user.update',
            'user.accessibility',
            'user.login',

            // Blogs
            'blog.all',
            'blog.store',
            'blog.update',
            'blog.delete',
            'blog.activate',
            'blog.specialize',

            // News
            'news.all',
            'news.store',
            'news.update',
            'news.delete',
            'news.activate',
            'news.specialize',

            // Products
            'product.all',
            'product.store',
            'product.update',
            'product.activate',
            'product.specialize',

            // Roles
            'role.all',
            'role.store',
            'role.update',
            'role.syncPermission',

            // Permissions
            'permission.all',
            'permission.store',
            'permission.update',

            // Tickets
            'ticket.store',
            'ticket.sendMessage',

            // Categories
            'category.all',
            'category.store',
            'category.update',
            'category.delete',
            'category.activate',
            'category.specialize',

            // Brands
            'brand.all',
            'brand.store',
            'brand.update',
            'brand.delete',
            'brand.activate',

            // Geography
            'state.all',
            'state.store',
            'state.update',
            'city.all',
            'city.store',
            'city.update',

            // Comments
            'comment.release',
            'comment.answer',
            'comment.specialize',

            // Invoices
            'invoice.all',
            'invoice.items',
            'invoice.detail',
            'invoice.user',

            // Relations
            'relation.attachCategory',
            'relation.attachTag',
            'relation.attachAttribute',
            'relation.attachLike',
            'relation.attachGallery',
        ];

        foreach ($permissions as $slug) {
            Permission::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => Str::headline($slug),
                    'guard_name' => 'web',
                ],
            );
        }
    }
}
