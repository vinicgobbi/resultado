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
        Schema::table('push_follows', function (Blueprint $table) {
            // What the last "new results" notification told: the election,
            // the step of the count and the leader.
            $table->string('last_update_key', 50)->nullable()->after('last_update_notified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_follows', function (Blueprint $table) {
            $table->dropColumn('last_update_key');
        });
    }
};
