<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')
                ->nullable()
                ->after('creator_id')
                ->constrained('brands')
                ->nullOnDelete();

            $table->unsignedInteger('sold_count')
                ->default(0)
                ->after('stock');

            $table->unsignedTinyInteger('discount_percent')
                ->nullable()
                ->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['sold_count', 'discount_percent']);
        });
    }
};
