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
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('election_code');
            $table->string('scope', 2);
            $table->string('scope_code', 5);
            $table->unsignedInteger('sections_total')->default(0);
            $table->unsignedInteger('sections_counted')->default(0);
            $table->decimal('counted_percent', 7, 4)->default(0);
            $table->unsignedInteger('electorate')->default(0);
            $table->unsignedInteger('turnout')->default(0);
            $table->unsignedInteger('abstentions')->default(0);
            $table->unsignedInteger('valid_votes')->default(0);
            $table->unsignedInteger('blank_votes')->default(0);
            $table->unsignedInteger('null_votes')->default(0);
            $table->json('votes');
            $table->foreignId('leading_candidate_id')->nullable()->constrained('candidates')->nullOnDelete();
            $table->boolean('is_final')->default(false);
            $table->dateTime('tse_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['election_code', 'scope', 'scope_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
