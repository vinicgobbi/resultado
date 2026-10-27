<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->unsignedInteger('election_code')->nullable()->after('tse_sequence');
            $table->string('municipality', 5)->nullable()->after('state');

            $table->index('election_code');
            $table->index(['office', 'state', 'municipality']);
        });

        // Candidates synchronized before elections were registered all ran
        // in the first round of 2026: 6257 (federal) and 6259 (state).
        DB::table('candidates')->where('office', 'presidente')->update(['election_code' => 6257]);
        DB::table('candidates')->whereNull('election_code')->update(['election_code' => 6259]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['office', 'state', 'municipality']);
            $table->dropIndex(['election_code']);
            $table->dropColumn(['election_code', 'municipality']);
        });
    }
};
