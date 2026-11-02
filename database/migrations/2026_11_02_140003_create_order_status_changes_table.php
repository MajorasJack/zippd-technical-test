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
        Schema::create('order_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamp('webhook_delivered_at')->nullable();
            $table->timestamp('webhook_failed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['order_id', 'to_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_changes');
    }
};
