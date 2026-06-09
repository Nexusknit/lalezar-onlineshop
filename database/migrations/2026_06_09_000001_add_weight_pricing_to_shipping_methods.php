<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table): void {
            $table->decimal('cost_per_kg', 12, 2)->default(0)->after('base_cost');
            $table->unsignedInteger('max_weight_grams')->nullable()->after('cost_per_kg');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table): void {
            $table->dropColumn(['cost_per_kg', 'max_weight_grams']);
        });
    }
};
