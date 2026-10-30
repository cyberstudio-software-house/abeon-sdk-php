<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The service's audit log (ADR-0042): who changed what, when and on whose behalf. Append-only in
 * the database itself: the triggers refuse every UPDATE, and every DELETE of an entry younger
 * than the shortest retention the platform allows, whichever code path asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abeon_audit_log', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at', 6)->index();
            $table->string('actor_type', 20);
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_name', 255)->nullable();
            $table->string('on_behalf_of', 64)->nullable();
            $table->unsignedBigInteger('org_id')->nullable();
            $table->string('action', 100)->index();
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'occurred_at']);
        });

        DB::unprepared("CREATE TRIGGER abeon_audit_log_no_update BEFORE UPDATE ON abeon_audit_log FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'abeon_audit_log is append-only'");
        DB::unprepared("CREATE TRIGGER abeon_audit_log_no_delete BEFORE DELETE ON abeon_audit_log FOR EACH ROW
            BEGIN
                IF OLD.occurred_at >= NOW() - INTERVAL 365 DAY THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'abeon_audit_log keeps every entry for at least 365 days';
                END IF;
            END");
    }

    public function down(): void
    {
        Schema::dropIfExists('abeon_audit_log');
    }
};
