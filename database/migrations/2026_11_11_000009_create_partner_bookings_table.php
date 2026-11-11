<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The PRETEND booking sites' own records (DemoStay, DemoFly). A real partner would keep these
        // in its own system; we only have this table so the demo sites have something to show.
        Schema::create('partner_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('reference', 40)->unique();
            // The booking in the partner's OWN format (each partner uses different field names on purpose).
            $table->json('payload');
            // Trip codes this booking was sent to: later changes are pushed to the same trips.
            $table->json('sent_to')->nullable();
            $table->timestamps();

            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_bookings');
    }
};
