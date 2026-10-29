<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The files module (ADR-0037). Published into an application with
 * `vendor:publish --tag=abeon-files-migrations` rather than loaded for every service, because
 * only applications that keep files want these tables. No `org_id`: an instance serves one
 * organisation (ADR-0031), and the prefix comes from `ABEON_SERVICE_NAME`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->restrictOnDelete();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('visibility', 16);
            $table->string('object_key', 1024)->nullable();
            $table->string('upload_id', 1024)->nullable();
            $table->string('original_name', 255);
            $table->string('slug', 120);
            $table->string('display_name', 255);
            $table->text('description')->nullable();
            $table->string('mime', 255);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64)->nullable()->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->decimal('focal_x', 5, 4)->nullable();
            $table->decimal('focal_y', 5, 4)->nullable();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->string('status', 16);
            $table->string('failure_reason', 64)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });

        Schema::create('file_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->string('preset', 32);
            $table->string('object_key', 1024);
            $table->string('mime', 255);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->unique(['file_id', 'preset', 'mime']);
        });

        Schema::create('file_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->string('entity_type', 120);
            $table->string('entity_id', 64);
            $table->string('label', 255)->nullable();
            $table->string('admin_url', 1024)->nullable();
            $table->timestamps();

            $table->unique(['file_id', 'entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->timestamps();
        });

        Schema::create('file_tag', function (Blueprint $table): void {
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->primary(['file_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('file_usages');
        Schema::dropIfExists('file_variants');
        Schema::dropIfExists('files');
        Schema::dropIfExists('folders');
    }
};
