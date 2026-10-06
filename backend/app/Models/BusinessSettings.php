<?php

namespace App\Models;

use Database\Factories\BusinessSettingsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton configuration row (id=1). No admin screen or endpoints yet —
 * seeded with defaults by BusinessSettingsSeeder and only read here so its
 * row can be locked (SELECT ... FOR UPDATE) during services/professionals
 * writes, per the locking strategy in docs/planejamento-barbearia-mvp.md.
 */
#[Fillable([
    'name', 'address', 'phone', 'timezone',
    'min_notice_minutes', 'booking_horizon_days',
    'cancel_min_notice_minutes', 'max_active_per_contact',
])]
class BusinessSettings extends Model
{
    /** @use HasFactory<BusinessSettingsFactory> */
    use HasFactory;
}
