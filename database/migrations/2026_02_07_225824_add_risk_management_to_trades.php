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
        Schema::table('trades', function (Blueprint $table) {
            $table->decimal('stop_loss_points', 14, 2)->nullable();
            $table->decimal('take_profit_points', 14, 2)->nullable();
            $table->unsignedInteger('max_hold_minutes')->nullable();
            $table->index(['status', 'opened_at'], 'trades_status_opened_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex('trades_status_opened_at_idx');
            $table->dropColumn(['stop_loss_points', 'take_profit_points', 'max_hold_minutes']);
        });
    }
};
