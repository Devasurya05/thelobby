<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per unexpected error. Never store tokens, cookies, passwords or file contents here. */
class ErrorLog extends Model
{
    // Only created_at exists on this table.
    public const UPDATED_AT = null;

    protected $fillable = [
        'trip_id', 'member_id', 'url', 'method', 'message', 'exception_class', 'file', 'line',
    ];
}
