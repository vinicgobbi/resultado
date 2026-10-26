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
            $table->string('office', 20)->default('presidente')->after('tse_sequence');
            $table->string('state', 2)->nullable()->after('office');

            $table->index(['office', 'state']);
        });

        Schema::table('results', function (Blueprint $table) {
            $table->string('office', 20)->default('presidente')->after('election_code');
            $table->unsignedSmallInteger('seats')->default(1)->after('votes');
            $table->json('elected_candidate_ids')->nullable()->after('leading_candidate_id');

            $table->dropUnique(['election_code', 'scope', 'scope_code']);
            $table->unique(['election_code', 'office', 'scope', 'scope_code']);
        });

        DB::table('results')
            ->whereNotNull('elected_candidate_id')
            ->get(['id', 'elected_candidate_id'])
            ->each(fn (object $result) => DB::table('results')
                ->where('id', $result->id)
                ->update(['elected_candidate_ids' => json_encode([(int) $result->elected_candidate_id])]));

        Schema::table('results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('elected_candidate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('results')->where('office', '!=', 'presidente')->delete();

        Schema::table('results', function (Blueprint $table) {
            $table->foreignId('elected_candidate_id')->nullable()->after('leading_candidate_id')
                ->constrained('candidates')->nullOnDelete();
        });

        DB::table('results')
            ->whereNotNull('elected_candidate_ids')
            ->get(['id', 'elected_candidate_ids'])
            ->each(fn (object $result) => DB::table('results')
                ->where('id', $result->id)
                ->update(['elected_candidate_id' => json_decode($result->elected_candidate_ids)[0] ?? null]));

        Schema::table('results', function (Blueprint $table) {
            $table->dropUnique(['election_code', 'office', 'scope', 'scope_code']);
            $table->unique(['election_code', 'scope', 'scope_code']);
            $table->dropColumn(['office', 'seats', 'elected_candidate_ids']);
        });

        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['office', 'state']);
            $table->dropColumn(['office', 'state']);
        });
    }
};
