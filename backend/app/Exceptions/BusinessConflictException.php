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

    public static function contactLimitReached(): self
    {
        return new self('CONTACT_LIMIT_REACHED', __('errors.contact_limit_reached'));
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', __('errors.idempotency_key_reused'));
    }
}
