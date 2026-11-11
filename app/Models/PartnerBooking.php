<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A booking inside one of the pretend booking sites (DemoStay, DemoFly), in that site's own format. */
class PartnerBooking extends Model
{
    protected $fillable = ['provider', 'reference', 'payload', 'sent_to'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_to' => 'array'];
    }
}
