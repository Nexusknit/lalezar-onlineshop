<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('external_id', 191);
            $table->string('checksum', 64)->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });

        Schema::create('accounting_invoice_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('external_id', 191)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 30)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });

        Schema::create('accounting_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retry_of_id')->nullable()->constrained('accounting_sync_logs')->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 50);
            $table->string('operation', 50);
            $table->nullableMorphs('syncable');
            $table->string('status', 30)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['operation', 'status']);
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_sync_logs');
        Schema::dropIfExists('accounting_invoice_mappings');
        Schema::dropIfExists('accounting_product_mappings');
    }
};
