<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brandables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('brandable_id');
            $table->string('brandable_type');
            $table->timestamps();

            $table->unique(['brand_id', 'brandable_id', 'brandable_type'], 'brandables_unique');
            $table->index(['brandable_id', 'brandable_type'], 'brandables_model_index');
        });

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'brand_id')) {
            $now = now();

            DB::table('products')
                ->select(['id', 'brand_id'])
                ->whereNotNull('brand_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($now): void {
                    $payload = [];

                    foreach ($rows as $row) {
                        $payload[] = [
                            'brand_id' => $row->brand_id,
                            'brandable_id' => $row->id,
                            'brandable_type' => \App\Models\Product::class,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($payload !== []) {
                        DB::table('brandables')->upsert(
                            $payload,
                            ['brand_id', 'brandable_id', 'brandable_type'],
                            ['updated_at']
                        );
                    }
                });

            Schema::table('products', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('brand_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('creator_id')
                    ->constrained('brands')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('brandables')) {
            DB::table('brandables')
                ->where('brandable_type', \App\Models\Product::class)
                ->orderBy('brandable_id')
                ->chunkById(500, static function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('products')
                            ->where('id', $row->brandable_id)
                            ->update(['brand_id' => $row->brand_id]);
                    }
                });
        }

        Schema::dropIfExists('brandables');
    }
};
