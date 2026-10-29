<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The photos command and the candidates page read
     * the candidates of an election by office; the new index starts with the
     * election, so the one on the election alone is no longer needed.
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->index(['election_code', 'office']);
            $table->dropIndex(['election_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->index('election_code');
            $table->dropIndex(['election_code', 'office']);
        });
    }
};
