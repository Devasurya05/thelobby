<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The trip's plan: where to be and when (stay, flight, tickets, a meal...). Shown on the Plan page
        // and turned into calendar events and map links.
        Schema::create('itinerary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            // If the organiser who added it is ever deleted, keep the item (just forget who added it).
            $table->foreignId('created_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('title', 120);
            // Local date and clock time AT THE PLACE. No time zone on purpose: "14:00" means 14:00 wherever you are.
            // A missing start time makes it an all-day event.
            $table->date('starts_on');
            $table->time('starts_time')->nullable();
            $table->date('ends_on')->nullable();
            $table->time('ends_time')->nullable();
            $table->string('place', 120)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'starts_on', 'starts_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_items');
    }
};
