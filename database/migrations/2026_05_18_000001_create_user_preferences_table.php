<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per ADR-0009.
 *
 * Owned by Auth service. SDK ships the migration so the SDK base
 * `PreferencesController` can query a stable schema; Auth runs the migration
 * via `loadMigrationsFrom()`. Other services do NOT need this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // Per user PER ORGANISATION (ADR-0009 as amended by ADR-0016). A user
            // in two organisations keeps separate app order, pins and theme in
            // each, because the app sets differ — a pin to /crm/contacts is
            // meaningless in an organisation that has no CRM.
            $table->unsignedBigInteger('org_id');
            $table->json('preferences');
            $table->timestamps();

            $table->unique(['user_id', 'org_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
