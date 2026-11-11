<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultFile extends Model
{
    protected $fillable = [
        'trip_id', 'uploaded_by_member_id', 'original_name', 's3_key', 'mime_type', 'size_bytes',
    ];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'uploaded_by_member_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
