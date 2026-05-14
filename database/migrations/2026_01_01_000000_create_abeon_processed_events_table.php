<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abeon_processed_events', function (Blueprint $table): void {
            $table->string('event_id', 36)->primary();
            $table->string('routing_key', 255);
            $table->timestamp('processed_at')->useCurrent();

            $table->index('routing_key');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abeon_processed_events');
    }
};
