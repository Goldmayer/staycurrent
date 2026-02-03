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
        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->string('symbol_code');
            $table->string('timeframe_code');
            $table->bigInteger('open_time_ms');
            $table->decimal('open', 16, 8);
            $table->decimal('high', 16, 8);
            $table->decimal('low', 16, 8);
            $table->decimal('close', 16, 8);
            $table->decimal('volume', 16, 8)->nullable();
            $table->bigInteger('close_time_ms')->nullable();
            $table->timestamps();

            $table->unique(['symbol_code', 'timeframe_code', 'open_time_ms']);
            $table->index(['symbol_code', 'timeframe_code', 'open_time_ms']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
