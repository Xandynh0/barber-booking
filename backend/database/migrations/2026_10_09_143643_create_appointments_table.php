<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure approved in docs/planejamento-barbearia-mvp.md, seção 4. This
 * delivery only creates the table so the availability engine can treat
 * existing reservations as busy time — nothing writes to it yet. Creating
 * a reservation (idempotency, contact limit, transactional revalidation
 * under lock) belongs to the public booking delivery.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // Restrict, not cascade: a professional or service with
            // reservations must never take them along when removed.
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('customer_name');
            // Canonical forms (trim/lowercase e-mail, E.164 phone); nullable
            // only for reservations made by the administrator.
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 32)->nullable();
            // Instants in UTC, same convention as schedule_blocks. ends_at
            // is computed by the server at creation and never recalculated.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // confirmed | cancelled
            $table->string('status', 16)->default('confirmed');
            // public | admin
            $table->string('source', 16);
            $table->string('service_name_snapshot');
            $table->unsignedInteger('duration_minutes_snapshot');
            $table->decimal('price_snapshot', 10, 2);
            $table->dateTime('cancelled_at')->nullable();
            // customer | admin
            $table->string('cancelled_by', 16)->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('request_fingerprint', 64);
            $table->timestamps();

            $table->index(['professional_id', 'starts_at']);
            $table->index(['customer_email', 'status', 'starts_at']);
            $table->index(['customer_phone', 'status', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
