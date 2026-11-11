<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    public const ROLE_ORGANISER = 'organiser';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    protected $fillable = ['trip_id', 'name', 'role', 'token_hash'];

    // Never include the token hash if a member is ever turned into JSON by accident.
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isOrganiser(): bool
    {
        return $this->role === self::ROLE_ORGANISER;
    }

    // Organisers and members may upload; viewers may only look.
    public function canUpload(): bool
    {
        return in_array($this->role, [self::ROLE_ORGANISER, self::ROLE_MEMBER], true);
    }
}
