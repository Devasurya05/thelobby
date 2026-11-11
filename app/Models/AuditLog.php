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

    /**
     * The one way the app writes to the audit log, so every entry looks the same.
     * Never put tokens, cookies or file contents in $details.
     */
    public static function record(
        int $tripId,
        ?int $memberId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $details = [],
    ): self {
        return self::create([
            'trip_id' => $tripId,
            'member_id' => $memberId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details === [] ? null : $details,
            'ip_address' => request()->ip(),
        ]);
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
