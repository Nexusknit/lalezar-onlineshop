<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Dashboard
            'dashboard.view',

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

            // Uploads
            'upload.store',

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
            'ticket.all',
            'ticket.store',
            'ticket.update',
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
            'comment.all',
            'comment.release',
            'comment.reject',
            'comment.answer',
            'comment.specialize',

            // Coupons
            'coupon.all',
            'coupon.store',
            'coupon.update',
            'coupon.delete',

            // Invoices
            'invoice.all',
            'invoice.items',
            'invoice.detail',
            'invoice.user',
            'invoice.updateStatus',

            // Relations
            'relation.attachCategory',
            'relation.attachTag',
            'relation.attachAttribute',
            'relation.attachLike',
            'relation.attachGallery',

            // Settings
            'setting.all',
            'setting.update',
        ];

        foreach ($permissions as $slug) {
            Permission::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $this->persianName($slug),
                    'guard_name' => 'web',
                ],
            );
        }
    }

    private function persianName(string $slug): string
    {
        [$resource, $action] = array_pad(explode('.', $slug, 2), 2, '');

        $resources = [
            'dashboard' => 'داشبورد',
            'user' => 'کاربر',
            'blog' => 'وبلاگ',
            'news' => 'خبر',
            'product' => 'محصول',
            'upload' => 'فایل',
            'role' => 'نقش',
            'permission' => 'مجوز',
            'ticket' => 'تیکت',
            'category' => 'دسته‌بندی',
            'brand' => 'برند',
            'state' => 'استان',
            'city' => 'شهر',
            'comment' => 'نظر',
            'coupon' => 'کوپن',
            'invoice' => 'فاکتور',
            'relation' => 'ارتباط',
            'setting' => 'تنظیمات',
        ];
        $actions = [
            'view' => 'مشاهده',
            'all' => 'مشاهده',
            'store' => 'ایجاد',
            'update' => 'ویرایش',
            'delete' => 'حذف',
            'activate' => 'فعال‌سازی',
            'specialize' => 'ویژه‌سازی',
            'accessibility' => 'تخصیص دسترسی',
            'login' => 'ورود جایگزین',
            'syncPermission' => 'اتصال مجوز',
            'sendMessage' => 'ارسال پیام',
            'release' => 'انتشار',
            'reject' => 'رد',
            'answer' => 'پاسخ',
            'items' => 'اقلام',
            'detail' => 'جزئیات',
            'user' => 'فاکتورهای کاربر',
            'updateStatus' => 'تغییر وضعیت',
            'attachCategory' => 'اتصال دسته',
            'attachTag' => 'اتصال تگ',
            'attachAttribute' => 'اتصال ویژگی',
            'attachLike' => 'اتصال پسند',
            'attachGallery' => 'اتصال گالری',
        ];

        return trim(($actions[$action] ?? $action).' '.($resources[$resource] ?? $resource));
    }
}
