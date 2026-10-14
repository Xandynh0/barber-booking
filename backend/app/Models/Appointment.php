<?php

namespace App\Models;

use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A reservation (docs/planejamento-barbearia-mvp.md, seções 3, 4 e 5).
 * Created by AppointmentBooker (public or admin), its status changed only
 * by AppointmentCanceller and AppointmentStatusChanger. Every status other
 * than `cancelled` keeps occupying the professional's time — completed and
 * no-show appointments preserve their interval in the history.
 */
#[Fillable([
    'professional_id', 'service_id',
    'customer_name', 'customer_email', 'customer_phone',
    'starts_at', 'ends_at', 'status', 'source',
    'service_name_snapshot', 'duration_minutes_snapshot', 'price_snapshot',
    'cancelled_at', 'cancelled_by',
    'idempotency_key', 'request_fingerprint',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, HasUlids;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Final; allowed once the appointment has ended (seção 3). */
    public const STATUS_COMPLETED = 'completed';

    /** Final; allowed once the appointment has started (seção 3). */
    public const STATUS_NO_SHOW = 'no_show';

    /** Every status, in the order the agenda filters list them. */
    public const STATUSES = [self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW, self::STATUS_CANCELLED];

    public const CANCELLED_BY_CUSTOMER = 'customer';

    public const CANCELLED_BY_ADMIN = 'admin';

    public const SOURCE_PUBLIC = 'public';

    public const SOURCE_ADMIN = 'admin';

    /**
     * The ULID goes to `public_id` only; the primary key stays numeric.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'duration_minutes_snapshot' => 'integer',
            'price_snapshot' => 'decimal:2',
        ];
    }

    /**
     * Reservations that still occupy the professional's time.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeOccupying(Builder $query): void
    {
        $query->where('status', '!=', self::STATUS_CANCELLED);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasOne<AppointmentNotification, $this>
     */
    public function confirmationNotification(): HasOne
    {
        return $this->hasOne(AppointmentNotification::class)->where('kind', AppointmentNotification::KIND_CONFIRMATION);
    }
}
