<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // organiser = everything, member = upload + view, viewer = view only.
            $table->enum('role', ['organiser', 'member', 'viewer'])->default('member');
            // We store only the SHA-256 hash of the member's secret token, never the token itself.
            // Same idea as storing password hashes: a database leak does not leak working logins.
            $table->char('token_hash', 64)->unique();
            // "Removed" members are kept (history, audit log) but can no longer do anything.
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
