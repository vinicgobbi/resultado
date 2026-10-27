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
        Schema::create('elections', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tse_code')->unique();
            $table->string('cycle', 10);
            $table->date('date')->index();
            $table->unsignedTinyInteger('round')->default(1);
            $table->string('type', 20);
            $table->string('name');
            $table->unsignedInteger('second_round_code')->nullable()->index();
            $table->json('places')->nullable();
            $table->json('offices');
            $table->date('available_until')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('elections');
    }
};
