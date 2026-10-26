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
        Schema::table('results', function (Blueprint $table) {
            $table->unsignedInteger('electorate_counted')->default(0)->after('electorate');
            $table->foreignId('elected_candidate_id')->nullable()->after('leading_candidate_id')
                ->constrained('candidates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('elected_candidate_id');
            $table->dropColumn('electorate_counted');
        });
    }
};
