<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Colors chosen in the admin, over the defaults of
     * config/partidos.php.
     */
    public function up(): void
    {
        Schema::create('party_colors', function (Blueprint $table) {
            $table->id();
            $table->string('party', 30)->unique();
            $table->string('color', 7);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('party_colors');
    }
};
