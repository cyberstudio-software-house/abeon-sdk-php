<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abeon_event_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->string('routing_key', 255);
            $table->longText('envelope'); // JSON-encoded envelope
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            $table->index(['processed_at', 'next_attempt_at'], 'abeon_outbox_pending_idx');
            $table->index('created_at');
            $table->index('routing_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abeon_event_outbox');
    }
};
