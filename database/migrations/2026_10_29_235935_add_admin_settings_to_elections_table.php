<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Set in the admin for a whole year (every round and
     * part of it), so they are the same on all of its elections.
     */
    public function up(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
            $table->boolean('is_hidden')->default(false)->after('display_name');
            $table->boolean('is_featured')->default(false)->after('is_hidden');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'is_hidden', 'is_featured']);
        });
    }
};
