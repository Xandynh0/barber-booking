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
        Schema::create('schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            // Instants in UTC, same convention as the rest of the project
            // (docs/planejamento-barbearia-mvp.md, seção 4).
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // Visible only to the administrator — never exposed publicly.
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_blocks');
    }
};
