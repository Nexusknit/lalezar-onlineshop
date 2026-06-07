<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('stock_reserved')->default(0)->after('stock');
            $table->string('barcode', 100)->nullable()->unique()->after('sku');
            $table->unsignedInteger('weight_grams')->nullable()->after('currency');
            $table->unsignedInteger('length_mm')->nullable()->after('weight_grams');
            $table->unsignedInteger('width_mm')->nullable()->after('length_mm');
            $table->unsignedInteger('height_mm')->nullable()->after('width_mm');
            $table->string('warranty')->nullable()->after('height_mm');
            $table->unsignedInteger('min_order_quantity')->default(1)->after('warranty');
            $table->unsignedInteger('max_order_quantity')->nullable()->after('min_order_quantity');
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 100)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('stock_reserved')->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->json('options')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->string('warranty')->nullable();
            $table->unsignedInteger('min_order_quantity')->default(1);
            $table->unsignedInteger('max_order_quantity')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['product_id', 'status', 'sort_order']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->index('cart_id', 'cart_items_cart_id_index');
            $table->index('product_id', 'cart_items_product_id_index');
            $table->dropUnique('cart_items_cart_id_product_id_unique');
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unique(
                ['cart_id', 'product_id', 'product_variant_id'],
                'cart_items_product_variant_unique'
            );
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('galleries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('alt');
            $table->boolean('is_primary')->default(false)->after('sort_order');
            $table->index(['model_type', 'model_id', 'sort_order']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->integer('stock_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->unsignedInteger('stock_after');
            $table->unsignedInteger('reserved_after');
            $table->string('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'product_variant_id', 'created_at'], 'inventory_target_created_index');
        });

        Schema::create('price_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2);
            $table->string('currency', 3);
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'product_variant_id', 'created_at'], 'price_target_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_histories');
        Schema::dropIfExists('inventory_movements');

        Schema::table('galleries', function (Blueprint $table): void {
            $table->dropIndex(['model_type', 'model_id', 'sort_order']);
            $table->dropColumn(['sort_order', 'is_primary']);
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_product_variant_unique');
            $table->dropConstrainedForeignId('product_variant_id');
            $table->unique(['cart_id', 'product_id']);
            $table->dropIndex('cart_items_cart_id_index');
        });

        Schema::dropIfExists('product_variants');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['barcode']);
            $table->dropColumn([
                'stock_reserved',
                'barcode',
                'weight_grams',
                'length_mm',
                'width_mm',
                'height_mm',
                'warranty',
                'min_order_quantity',
                'max_order_quantity',
            ]);
        });
    }
};
