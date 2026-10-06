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
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->unsignedInteger('min_notice_minutes')->default(60);
            $table->unsignedInteger('booking_horizon_days')->default(30);
            $table->unsignedInteger('cancel_min_notice_minutes')->default(0);
            $table->unsignedInteger('max_active_per_contact')->default(3);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
