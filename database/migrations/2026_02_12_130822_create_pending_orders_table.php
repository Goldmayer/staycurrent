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
        Schema::create('pending_orders', function (Blueprint $table) {
            $table->id();
            $table->string('symbol_code');
            $table->string('timeframe_code');
            $table->string('side'); // 'buy' or 'sell'
            $table->decimal('entry_price', 16, 8);
            $table->json('meta')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['symbol_code']);
            $table->index(['timeframe_code']);
            $table->index(['side']);

            // Unique constraint on symbol_code and timeframe_code
            $table->unique(['symbol_code', 'timeframe_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_orders');
    }
};
