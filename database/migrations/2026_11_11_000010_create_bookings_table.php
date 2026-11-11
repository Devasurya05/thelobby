<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A booking that came from a booking site, as The Lobby knows it. `data` is the STANDARD format
        // (the same shape for every partner), not the partner's own format.
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('reference', 40);
            // pending (waiting for the organiser), applied, needs_attention, cancelled, dismissed
            $table->string('status', 20);
            // The partner's version number. A message with a version we already have is a duplicate.
            $table->unsignedInteger('version');
            $table->json('data');
            // Plain-language note when part of a change could not be applied (for example a room with people in it).
            $table->string('attention', 600)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            // The database itself refuses a second row for the same booking in the same trip,
            // even if two messages arrive at the same moment.
            $table->unique(['trip_id', 'provider', 'reference']);
            $table->index(['trip_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
