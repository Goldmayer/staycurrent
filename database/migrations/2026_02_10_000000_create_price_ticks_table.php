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
        Schema::create('price_ticks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol_code');
            $table->decimal('price', 18, 8);
            $table->timestamp('pulled_at');
            $table->timestamps();

            $table->index(['symbol_code', 'pulled_at']);
            $table->index('pulled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_ticks');
    }
};
