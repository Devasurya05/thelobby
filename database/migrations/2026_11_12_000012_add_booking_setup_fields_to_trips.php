<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // True for a trip created with "the rooms and dates will come from a booking". Its dates are placeholders
            // until the first booking arrives, and that first booking is accepted without a second tap.
            $table->boolean('awaiting_booking')->default(false)->after('ends_on');
            // The booking whose dates the trip follows. If that booking is moved, the trip's dates move with it.
            $table->foreignId('dates_booking_id')->nullable()->after('awaiting_booking')->constrained('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dates_booking_id');
            $table->dropColumn('awaiting_booking');
        });
    }
};
