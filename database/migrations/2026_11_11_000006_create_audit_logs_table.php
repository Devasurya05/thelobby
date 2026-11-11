<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete: the database refuses to delete a trip that still has audit history.
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('action', 50);          // e.g. bed_held, bed_confirmed, grab_rejected, file_opened
            $table->string('target_type', 50)->nullable(); // e.g. bed, vault_file, member
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            // Only created_at: an audit row is written once and never changed.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['trip_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
