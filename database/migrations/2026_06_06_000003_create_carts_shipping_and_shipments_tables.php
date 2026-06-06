<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->uuid('token')->nullable()->unique();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status', 20)->default('active')->index();
            $table->decimal('base_cost', 12, 2)->default(0);
            $table->decimal('free_threshold', 12, 2)->nullable();
            $table->json('state_ids')->nullable();
            $table->json('city_ids')->nullable();
            $table->unsignedSmallInteger('estimated_days_min')->nullable();
            $table->unsignedSmallInteger('estimated_days_max')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('shipping_method_id')
                ->nullable()
                ->after('coupon_id')
                ->constrained()
                ->nullOnDelete();
            $table->decimal('shipping', 12, 2)->default(0)->after('discount');
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('preparing')->index();
            $table->string('carrier')->nullable();
            $table->string('tracking_code')->nullable()->index();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipping_method_id');
            $table->dropColumn('shipping');
        });

        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
