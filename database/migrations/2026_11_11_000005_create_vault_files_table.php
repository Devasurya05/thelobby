<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            // Only for display. The file in S3 is stored under a random key, never under this name.
            $table->string('original_name');
            $table->string('s3_key')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            // Set when the file has been deleted from S3. The row stays as a record.
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_files');
    }
};
