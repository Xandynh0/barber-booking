<?php

namespace App\Models;

use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reservation. Only read in this delivery (the availability engine treats
 * every non-cancelled row as busy time); creating one — idempotency,
 * contact limit and transactional revalidation under lock — belongs to the
 * public booking delivery. See docs/planejamento-barbearia-mvp.md, seções
 * 4 e 5.
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
}
