<?php

use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\BrandController as AdminBrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CommentController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\NewsController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RelationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StateController;
use App\Http\Controllers\Admin\CityController as AdminCityController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\Home\BlogsController as HomeBlogsController;
use App\Http\Controllers\Home\BrandController as HomeBrandController;
use App\Http\Controllers\Home\CategoryController as HomeCategoryController;
use App\Http\Controllers\Home\LocationController as HomeLocationController;
use App\Http\Controllers\Home\NewsController as HomeNewsController;
use App\Http\Controllers\Home\ProductController as HomeProductController;
use App\Http\Controllers\Home\SearchController as HomeSearchController;
use App\Http\Controllers\Home\TeamController as HomeTeamController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\User\FavoriteController as UserFavoriteController;
use App\Http\Controllers\User\AddressController as UserAddressController;
use App\Http\Controllers\User\CommentController as UserCommentController;
use App\Http\Controllers\User\ProfileController as UserProfileController;
use App\Http\Controllers\User\TicketController as UserTicketController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth-login')
    ->name('auth.login');
Route::post('/auth/verify', [AuthController::class, 'verify'])
    ->middleware('throttle:auth-verify')
    ->name('auth.verify');

Route::post('/cart/check', [CartController::class, 'check'])->name('cart.check');
Route::post('/cart/coupon', [CouponController::class, 'preview'])->name('cart.coupon');

Route::prefix('home')
    ->as('home.')
    ->group(function (): void {
        Route::get('/products', [HomeProductController::class, 'all'])->name('products.index');
        Route::get('/products/{product:slug}', [HomeProductController::class, 'single'])->name('products.show');

        Route::get('/news', [HomeNewsController::class, 'all'])->name('news.index');
        Route::get('/news/{news:slug}', [HomeNewsController::class, 'single'])->name('news.show');

        Route::get('/blogs', [HomeBlogsController::class, 'all'])->name('blogs.index');
        Route::get('/blogs/{blog:slug}', [HomeBlogsController::class, 'single'])->name('blogs.show');

        Route::get('/categories', [HomeCategoryController::class, 'all'])->name('categories.index');
        Route::get('/categories/{category:slug}', [HomeCategoryController::class, 'single'])->name('categories.show');
        Route::get('/states', [HomeLocationController::class, 'states'])->name('states.index');
        Route::get('/cities', [HomeLocationController::class, 'cities'])->name('cities.index');

        Route::get('/brands', [HomeBrandController::class, 'all'])->name('brands.index');
        Route::get('/team', [HomeTeamController::class, 'all'])->name('team.index');

        Route::get('/search', [HomeSearchController::class, 'index'])->name('search');
    });

Route::prefix('user')
    ->middleware(['auth:sanctum'])
    ->as('user.')
    ->group(function (): void {
        Route::get('/profile', [UserProfileController::class, 'show'])->name('profile.show');
        Route::match(['put', 'patch'], '/profile', [UserProfileController::class, 'update'])->name('profile.update');

        Route::get('/tickets', [UserTicketController::class, 'index'])->name('tickets.index');
        Route::post('/tickets', [UserTicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets/{ticket}', [UserTicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/messages', [UserTicketController::class, 'sendMessage'])->name('tickets.messages.store');

        Route::get('/favorites', [UserFavoriteController::class, 'index'])->name('favorites.index');
        Route::post('/favorites', [UserFavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('/favorites/{product}', [UserFavoriteController::class, 'destroy'])->name('favorites.destroy');

        Route::post('/comments', [UserCommentController::class, 'store'])->name('comments.store');

        Route::get('/addresses', [UserAddressController::class, 'index'])->name('addresses.index');
        Route::post('/addresses', [UserAddressController::class, 'store'])->name('addresses.store');
        Route::get('/addresses/{address}', [UserAddressController::class, 'show'])->name('addresses.show');
        Route::match(['put', 'patch'], '/addresses/{address}', [UserAddressController::class, 'update'])->name('addresses.update');
        Route::delete('/addresses/{address}', [UserAddressController::class, 'destroy'])->name('addresses.destroy');

        Route::post('/checkout', [PaymentController::class, 'checkout'])->name('checkout');
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum'])
    ->as('admin.')
    ->group(function (): void {
        // Users
        Route::get('/users', [UserController::class, 'all'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/accessibility', [UserController::class, 'accessibility'])->name('users.accessibility');
        Route::post('/users/{user}/impersonate', [UserController::class, 'loginAsUser'])->name('users.impersonate');

        Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store');

        // Blogs
        Route::get('/blogs', [BlogController::class, 'all'])->name('blogs.index');
        Route::post('/blogs', [BlogController::class, 'store'])->name('blogs.store');
        Route::match(['put', 'patch'], '/blogs/{blog}', [BlogController::class, 'update'])->name('blogs.update');
        Route::delete('/blogs/{blog}', [BlogController::class, 'delete'])->name('blogs.delete');
        Route::post('/blogs/{blog}/activate', [BlogController::class, 'activate'])->name('blogs.activate');
        Route::post('/blogs/{blog}/specialize', [BlogController::class, 'specialize'])->name('blogs.specialize');

        // Brands
        Route::get('/brands', [AdminBrandController::class, 'all'])->name('brands.index');
        Route::post('/brands', [AdminBrandController::class, 'store'])->name('brands.store');
        Route::match(['put', 'patch'], '/brands/{brand}', [AdminBrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{brand}', [AdminBrandController::class, 'delete'])->name('brands.delete');
        Route::post('/brands/{brand}/activate', [AdminBrandController::class, 'activate'])->name('brands.activate');

        // News
        Route::get('/news', [NewsController::class, 'all'])->name('news.index');
        Route::post('/news', [NewsController::class, 'store'])->name('news.store');
        Route::match(['put', 'patch'], '/news/{news}', [NewsController::class, 'update'])->name('news.update');
        Route::delete('/news/{news}', [NewsController::class, 'delete'])->name('news.delete');
        Route::post('/news/{news}/activate', [NewsController::class, 'activate'])->name('news.activate');
        Route::post('/news/{news}/specialize', [NewsController::class, 'specialize'])->name('news.specialize');

        // Products
        Route::get('/products', [ProductController::class, 'all'])->name('products.index');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/activate', [ProductController::class, 'activate'])->name('products.activate');
        Route::post('/products/{product}/specialize', [ProductController::class, 'specialize'])->name('products.specialize');

        // Roles & Permissions
        Route::get('/roles', [RoleController::class, 'all'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::match(['put', 'patch'], '/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::post('/roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->name('roles.permissions.sync');

        Route::get('/permissions', [PermissionController::class, 'all'])->name('permissions.index');
        Route::post('/permissions', [PermissionController::class, 'store'])->name('permissions.store');
        Route::match(['put', 'patch'], '/permissions/{permission}', [PermissionController::class, 'update'])->name('permissions.update');

        // Tickets
        Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::post('/tickets/{ticket}/messages', [TicketController::class, 'sendMessage'])->name('tickets.messages.store');

        // Categories
        Route::get('/categories', [CategoryController::class, 'all'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'delete'])->name('categories.delete');
        Route::post('/categories/{category}/activate', [CategoryController::class, 'activate'])->name('categories.activate');
        Route::post('/categories/{category}/specialize', [CategoryController::class, 'specialize'])->name('categories.specialize');

        // Geography
        Route::get('/states', [StateController::class, 'all'])->name('states.index');
        Route::post('/states', [StateController::class, 'store'])->name('states.store');
        Route::match(['put', 'patch'], '/states/{state}', [StateController::class, 'update'])->name('states.update');

        Route::get('/cities', [AdminCityController::class, 'all'])->name('cities.index');
        Route::post('/cities', [AdminCityController::class, 'store'])->name('cities.store');
        Route::match(['put', 'patch'], '/cities/{city}', [AdminCityController::class, 'update'])->name('cities.update');

        // Comments
        Route::post('/comments/{comment}/release', [CommentController::class, 'release'])->name('comments.release');
        Route::post('/comments/{comment}/answer', [CommentController::class, 'answer'])->name('comments.answer');
        Route::post('/comments/{comment}/specialize', [CommentController::class, 'specialize'])->name('comments.specialize');

        // Invoices
        Route::get('/invoices', [InvoiceController::class, 'all'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'detail'])->name('invoices.show');
        Route::get('/invoices/{invoice}/items', [InvoiceController::class, 'items'])->name('invoices.items');
        Route::get('/users/{user}/invoices', [InvoiceController::class, 'user'])->name('users.invoices');

        // Relations
        Route::post('/relations/categories', [RelationController::class, 'attachCategory'])->name('relations.categories');
        Route::post('/relations/tags', [RelationController::class, 'attachTag'])->name('relations.tags');
        Route::post('/relations/attributes', [RelationController::class, 'attachAttribute'])->name('relations.attributes');
        Route::post('/relations/likes', [RelationController::class, 'attachLike'])->name('relations.likes');
        Route::post('/relations/galleries', [RelationController::class, 'attachGallery'])->name('relations.galleries');
    });
