<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->enum('status', ['free', 'held', 'taken'])->default('free');

            // Temporary hold: who holds it and until when.
            $table->foreignId('held_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('hold_expires_at')->nullable();

            // Permanent booking. UNIQUE = the database itself refuses to let one person take two beds,
            // even if a bug in our PHP code ever tried. (MySQL allows many NULLs in a unique column,
            // so all the not-yet-taken beds are fine.)
            $table->foreignId('taken_by_member_id')->nullable()->unique()->constrained('members')->nullOnDelete();

            $table->timestamps();

            // The lobby screen and the expiry job both ask "beds of this trip with this status".
            $table->index(['trip_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};
