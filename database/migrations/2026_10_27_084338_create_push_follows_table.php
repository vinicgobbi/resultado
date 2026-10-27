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
        Schema::create('push_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('push_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('office', 30);
            // Where the office is elected: "br", a state or a TSE municipality code.
            $table->string('scope_code', 5);
            $table->boolean('notify_updates')->default(true);
            $table->boolean('notify_decided')->default(true);
            $table->boolean('notify_final')->default(true);
            $table->timestamp('last_update_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['push_subscription_id', 'office', 'scope_code']);
            $table->index(['office', 'scope_code']);
        });

        // Browsers subscribed before following existed received the national
        // presidential notifications: they keep following them.
        $now = now();

        DB::table('push_subscriptions')->orderBy('id')->chunkById(500, function ($subscriptions) use ($now): void {
            DB::table('push_follows')->insert($subscriptions->map(fn (object $subscription): array => [
                'push_subscription_id' => $subscription->id,
                'office' => 'presidente',
                'scope_code' => 'br',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_follows');
    }
};
