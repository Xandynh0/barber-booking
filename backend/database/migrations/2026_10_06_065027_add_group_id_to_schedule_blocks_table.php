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
        Schema::table('schedule_blocks', function (Blueprint $table) {
            // Nullable and additive on purpose: existing rows stay null
            // (individual blocks) — no backfill, no reinterpretation of
            // preexisting data. Only ties together the rows a single
            // "shop" scope closure creates (see ScheduleBlockController).
            $table->ulid('group_id')->nullable()->after('professional_id');
            $table->index('group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_blocks', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropColumn('group_id');
        });
    }
};
