<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which booking created this room or plan item (null = typed in by hand). Lets a later change
        // to the booking find, update or remove exactly the rows it created and nothing else.
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('trip_id')->constrained('bookings')->nullOnDelete();
        });

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('trip_id')->constrained('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
        });

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
        });
    }
};
