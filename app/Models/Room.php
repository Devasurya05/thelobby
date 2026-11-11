<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $fillable = ['trip_id', 'booking_id', 'name'];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }
}
