<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Corrections made in the admin, kept apart from
     * what the TSE publishes (the sync rewrites those).
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('custom_ballot_name')->nullable()->after('ballot_name');
            $table->string('custom_photo')->nullable()->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['custom_ballot_name', 'custom_photo']);
        });
    }
};
