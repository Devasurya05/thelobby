<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Short random code people type or scan to join. UNIQUE so two trips can never share one.
            $table->string('join_code', 8)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            // Set when the trip is over (end date passed, or organiser clicked "End trip now").
            $table->timestamp('ended_at')->nullable();
            // Set once every vault file has been deleted from S3. Lets the purge job skip finished trips.
            $table->timestamp('vault_purged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
