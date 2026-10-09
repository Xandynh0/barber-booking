<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery state of a transactional e-mail about a reservation
 * (docs/planejamento-barbearia-mvp.md, seções 4 e 6). Only the
 * `confirmation` kind exists in the MVP.
 */
#[Fillable(['appointment_id', 'kind', 'status', 'attempts', 'sent_at', 'last_error_code'])]
class AppointmentNotification extends Model
{
    public const KIND_CONFIRMATION = 'confirmation';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
