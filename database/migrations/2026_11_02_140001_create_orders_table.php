<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained();
            $table->string('partner_reference', 100);
            $table->string('idempotency_key')->nullable();
            $table->string('status');
            $table->json('collection_address');
            $table->json('delivery_address');
            $table->decimal('collection_latitude', 10, 7)->nullable();
            $table->decimal('collection_longitude', 10, 7)->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->timestamp('geocode_failed_at')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'partner_reference']);
            $table->unique(['partner_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
