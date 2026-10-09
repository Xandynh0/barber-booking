<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure approved in docs/planejamento-barbearia-mvp.md, seção 4: one
 * logical confirmation per reservation, created `pending` inside the
 * reservation's transaction and delivered after commit. No signed URL,
 * token or encrypted payload is stored — the cancellation link is signed
 * again at each send.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->restrictOnDelete();
            // confirmation
            $table->string('kind', 32);
            // pending | sent | failed | skipped
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('sent_at')->nullable();
            // Exception class only — never the message, which may carry
            // addresses or provider details.
            $table->string('last_error_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['appointment_id', 'kind']);
            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_notifications');
    }
};
