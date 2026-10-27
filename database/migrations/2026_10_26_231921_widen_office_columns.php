<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: "conselheiro-distrital" does not fit in 20
     * characters.
     */
    public function up(): void
    {
        foreach (['candidates', 'results'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('office', 30)->default('presidente')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['candidates', 'results'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('office', 20)->default('presidente')->change();
            });
        }
    }
};
