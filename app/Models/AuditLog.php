<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of everything that happens in a trip.
 * Rows can be created but never changed or deleted by the app.
 */
class AuditLog extends Model
{
    // The table has only created_at, no updated_at.
    public const UPDATED_AT = null;

    protected $fillable = ['trip_id', 'member_id', 'action', 'target_type', 'target_id', 'details', 'ip_address'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    protected static function booted(): void
    {
        // Safety net: if any code ever tries to edit or delete an audit row, fail loudly.
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
