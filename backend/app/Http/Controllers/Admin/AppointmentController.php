<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminAppointmentRequest;
use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Services\Agenda\AdminAppointmentPresenter;
use App\Services\Booking\AppointmentBooker;
use App\Services\Booking\AppointmentStatusChanger;
use App\Services\Booking\ConfirmationNotifier;
use App\Services\Booking\ConfirmationResender;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Reservations operated from the admin agenda. Every route sits behind
 * `auth:sanctum` (routes/api.php); reservations are addressed by their
 * `public_id`, never by the internal numeric id.
 */
class AppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentBooker $booker,
        private readonly AppointmentStatusChanger $statuses,
        private readonly ConfirmationNotifier $notifier,
        private readonly ConfirmationResender $resender,
    ) {}

    public function show(Appointment $appointment): JsonResponse
    {
        return $this->respond($appointment);
    }

    /**
     * 201 when created, 200 with the same body for an idempotent replay
     * (no new reservation, no new e-mail). The confirmation, when there is
     * one, is sent only after the reservation committed; its outcome is
     * reported in `notification_status` (`none` when there is no e-mail or
     * for "Atender agora") and never undoes the reservation.
     */
    public function store(StoreAdminAppointmentRequest $request): JsonResponse
    {
        $result = $this->booker->bookAdmin($request->booking(), $request->idempotencyKey());

        if (! $result['replayed']) {
            $notification = $result['appointment']->confirmationNotification;
            if ($notification !== null) {
                DB::afterCommit(fn () => $this->notifier->deliver($notification));
            }
        }

        return $this->respond($result['appointment']->refresh(), $result['replayed'] ? 200 : 201, ['replayed' => $result['replayed']]);
    }

    public function cancel(Appointment $appointment): JsonResponse
    {
        $result = $this->statuses->cancelByAdmin($appointment->public_id);

        return $this->respond($result['appointment'], 200, ['changed' => $result['changed']]);
    }

    public function complete(Appointment $appointment): JsonResponse
    {
        $result = $this->statuses->complete($appointment->public_id);

        return $this->respond($result['appointment'], 200, ['changed' => $result['changed']]);
    }

    public function noShow(Appointment $appointment): JsonResponse
    {
        $result = $this->statuses->markNoShow($appointment->public_id);

        return $this->respond($result['appointment'], 200, ['changed' => $result['changed']]);
    }

    /**
     * The outcome of this one attempt (`meta.attempt_status`: sent or
     * failed — or whatever a concurrent sender left) is reported apart from
     * the reservation, which is not changed by it.
     */
    public function resendConfirmation(Appointment $appointment): JsonResponse
    {
        $notification = $this->resender->resend($appointment);

        return $this->respond($appointment->refresh(), 200, ['attempt_status' => $notification->status]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function respond(Appointment $appointment, int $status = 200, array $meta = []): JsonResponse
    {
        $timezone = BusinessSettings::query()->firstOrFail()->timezone;
        $body = ['data' => AdminAppointmentPresenter::details($appointment->load(['professional', 'confirmationNotification']), $timezone)];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }
}
