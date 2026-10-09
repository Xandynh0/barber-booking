<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but conflicts with the current state of the
 * agenda. Rendered by bootstrap/app.php as
 * `409 { "error": { "code", "message", ...$details } }` — `code` is the
 * stable contract (docs/planejamento-barbearia-mvp.md, seção 11), `message`
 * is already translated.
 */
class BusinessConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  Extra keys merged into the `error` object.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function slotUnavailable(): self
    {
        return new self('SLOT_UNAVAILABLE', __('errors.slot_unavailable'));
    }

    /**
     * Admin flows: same stable code as the public one, plus `reason`
     * (past, outside_horizon, outside_working_hours, blocked, busy,
     * off_grid) so the agenda can say exactly why — never which customer
     * holds the time.
     */
    public static function slotUnavailableBecause(string $reason): self
    {
        return new self('SLOT_UNAVAILABLE', __("errors.admin_slot_{$reason}"), ['reason' => $reason]);
    }

    /**
     * `reason`: final_state (cancelled, completed and no_show are final),
     * not_started (no-show before the start) or not_finished (completed
     * before the end). `status` is the reservation's current status.
     */
    public static function statusTransitionNotAllowed(string $currentStatus, string $reason): self
    {
        return new self('STATUS_TRANSITION_NOT_ALLOWED', __("errors.transition_{$reason}"), [
            'status' => $currentStatus,
            'reason' => $reason,
        ]);
    }

    /**
     * `reason`: no_email, not_confirmed, not_future or attempts_exhausted.
     */
    public static function resendNotAllowed(string $reason): self
    {
        return new self('RESEND_NOT_ALLOWED', __("errors.resend_{$reason}"), ['reason' => $reason]);
    }

    public static function contactLimitReached(): self
    {
        return new self('CONTACT_LIMIT_REACHED', __('errors.contact_limit_reached'));
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', __('errors.idempotency_key_reused'));
    }
}
