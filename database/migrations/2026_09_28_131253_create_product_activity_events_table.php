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
        Schema::create('product_activity_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('action');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('occurred_at');
            $table->json('before');
            $table->json('after');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_activity_events');
    }
};
