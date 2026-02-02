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
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('symbol_code')->index();
            $table->string('timeframe_code')->index();

            $table->string('side')->index();
            $table->string('status')->index();

            $table->timestamp('opened_at')->index();
            $table->timestamp('closed_at')->nullable()->index();

            $table->decimal('entry_price', 16, 8);
            $table->decimal('exit_price', 16, 8)->nullable();

            $table->decimal('realized_points', 14, 2)->default(0);
            $table->decimal('unrealized_points', 14, 2)->default(0);

            $table->json('meta')->nullable();

            $table->index(['symbol_code', 'timeframe_code']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
