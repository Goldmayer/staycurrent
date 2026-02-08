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
        Schema::create('trade_monitors', function (Blueprint $table) {
            $table->id();
            $table->string('symbol_code');
            $table->string('timeframe_code');
            $table->text('expectation')->nullable();
            $table->unsignedBigInteger('open_trade_id')->nullable();
            $table->timestamps();

            $table->index(['symbol_code', 'timeframe_code'], 'symbol_timeframe_idx');
            $table->index('open_trade_id', 'open_trade_id_idx');
            $table->unique(['symbol_code', 'timeframe_code'], 'symbol_timeframe_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_monitors');
    }
};
