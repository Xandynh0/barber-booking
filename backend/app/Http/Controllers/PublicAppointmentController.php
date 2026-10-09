<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePublicAppointmentRequest;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BusinessSettings;
use App\Services\Booking\AppointmentBooker;
use App\Services\Booking\ConfirmationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PublicAppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentBooker $booker,
        private readonly ConfirmationNotifier $notifier,
    ) {}

    /**
     * 201 when the reservation is created; 200 with the same body when the
     * request is an idempotent replay of one already created (no new
     * reservation, no new e-mail). Conflicts are 409
     * (BusinessConflictException), format and invalid service/professional
     * are 422.
     *
     * The confirmation e-mail is sent only after the reservation committed:
     * DB::afterCommit() runs right away when no transaction is open (the
     * booker's own transaction has already returned here) and would wait
     * for the outermost commit if this were ever called inside one.
     */
    public function store(StorePublicAppointmentRequest $request): JsonResponse
    {
        $result = $this->booker->bookPublic($request->booking(), $request->idempotencyKey());

        if (! $result['replayed']) {
            $notification = $result['appointment']->confirmationNotification;
            if ($notification !== null) {
                DB::afterCommit(fn () => $this->notifier->deliver($notification));
            }
        }

        return response()->json(
            ['data' => $this->present($result['appointment'])],
            $result['replayed'] ? 200 : 201,
        );
    }

    /**
     * The service is described by the snapshots taken at creation, so a
     * later change of name/price/duration never alters what this response
     * reports. E-mail and phone are not echoed back.
     *
     * @return array<string, mixed>
     */
    private function present(Appointment $appointment): array
    {
        $appointment->loadMissing('professional');

        return [
            'public_id' => $appointment->public_id,
            'status' => $appointment->status,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'timezone' => BusinessSettings::query()->firstOrFail()->timezone,
            'service' => [
                'id' => $appointment->service_id,
                'name' => $appointment->service_name_snapshot,
                'duration_minutes' => $appointment->duration_minutes_snapshot,
                'price' => $appointment->price_snapshot,
            ],
            'professional' => [
                'id' => $appointment->professional->id,
                'name' => $appointment->professional->name,
            ],
            'customer_name' => $appointment->customer_name,
            // pending | sent | failed | skipped (seção 11). A failed e-mail
            // never undoes the reservation; the sweep retries it.
            'notification_status' => $appointment->confirmationNotification()->value('status')
                ?? AppointmentNotification::STATUS_SKIPPED,
        ];
    }
}
