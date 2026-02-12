<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_monitors', function (Blueprint $table) {
            $table->string('last_notified_state')->nullable()->after('open_trade_id');
        });
    }

    public function down(): void
    {
        Schema::table('trade_monitors', function (Blueprint $table) {
            $table->dropColumn('last_notified_state');
        });
    }
};
