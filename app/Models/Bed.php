<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bed extends Model
{
    public const FREE = 'free';

    public const HELD = 'held';

    public const TAKEN = 'taken';

    protected $fillable = ['trip_id', 'room_id', 'label'];

    protected function casts(): array
    {
        return ['hold_expires_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'held_by_member_id');
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'taken_by_member_id');
    }

    // A hold whose time has run out counts as free even if the scheduler has not cleaned it up yet.
    // Reading state through this method keeps the screen correct between scheduler runs.
    public function holdHasExpired(): bool
    {
        return $this->status === self::HELD
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isPast();
    }

    public function isAvailable(): bool
    {
        return $this->status === self::FREE || $this->holdHasExpired();
    }
}
