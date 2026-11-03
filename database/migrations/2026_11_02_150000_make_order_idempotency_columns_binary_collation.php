<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Compare the idempotency columns byte for byte, so references differing only by case or accent stay distinct.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('partner_reference', 100)->collation('utf8mb4_bin')->change();
            $table->string('idempotency_key')->nullable()->collation('utf8mb4_bin')->change();
        });
    }

    /**
     * Restore the case- and accent-insensitive collation.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('partner_reference', 100)->collation('utf8mb4_unicode_ci')->change();
            $table->string('idempotency_key')->nullable()->collation('utf8mb4_unicode_ci')->change();
        });
    }
};
