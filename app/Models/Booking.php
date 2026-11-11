<?php

namespace App\Models;

use App\Integrations\BookingData;
use App\Integrations\Providers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A booking from a booking site, in the standard format, and what has been done with it in this trip. */
class Booking extends Model
{
    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const NEEDS_ATTENTION = 'needs_attention';

    public const CANCELLED = 'cancelled';

    public const DISMISSED = 'dismissed';

    protected $fillable = ['trip_id', 'provider', 'reference', 'status', 'version', 'data', 'attention', 'applied_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'applied_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItineraryItem::class);
    }

    public function bookingData(): BookingData
    {
        return BookingData::fromArray($this->data);
    }

    public function providerLabel(): string
    {
        return Providers::find($this->provider)?->label() ?? $this->provider;
    }

    /** Statuses in which the booking's rooms and plan items exist in the trip. */
    public function isInTrip(): bool
    {
        return in_array($this->status, [self::APPLIED, self::NEEDS_ATTENTION, self::CANCELLED], true);
    }
}
