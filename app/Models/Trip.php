<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    // $fillable = the only fields that may be set in bulk from request data
    // (protection against "mass assignment" attacks, like a Pydantic schema whitelisting fields).
    protected $fillable = ['name', 'join_code', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'ended_at' => 'datetime',
            'vault_purged_at' => 'datetime',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    public function vaultFiles(): HasMany
    {
        return $this->hasMany(VaultFile::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }
}
