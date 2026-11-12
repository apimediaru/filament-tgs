<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fresh package installs only. The starter uses its historical chain + prefix migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tgs_blocks', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('type')->index();
            $table->string('owner_container_type');
            $table->string('owner_container_id');
            $table->index(['owner_container_type', 'owner_container_id']);
            $table->json('data')->nullable();
            $table->boolean('is_searchable')->default(true);
            $table->boolean('is_locked')->default(false);
            $table->string('lock_mode')->default('cascade');
            $table->timestamp('committed_at')->nullable();
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_op_id', 26)->nullable();
            $table->timestamps();
        });
        Schema::create('tgs_block_placements', function (Blueprint $table): void {
            $table->id();
            $table->string('container_type');
            $table->string('container_id');
            $table->string('slot');
            $table->orderingRank();
            $table->string('block_id', 26);
            $table->timestamps();
            $table->foreign('block_id')->references('id')->on('tgs_blocks')->cascadeOnDelete();
            $table->unique(['container_type', 'container_id', 'slot', 'rank'], 'tgs_placements_slot_rank_unique');
            $table->index('block_id');
            $table->index(['container_type', 'container_id', 'slot']);
        });
        Schema::create('tgs_block_media', function (Blueprint $table): void {
            $table->id();
            $table->string('model_type');
            $table->string('model_id', 26);
            $table->index(['model_type', 'model_id']);
            $table->foreign('model_id')->references('id')->on('tgs_blocks')->cascadeOnDelete();
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
        Schema::create('tgs_canvases', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->nullable()->unique();
            $table->string('key')->nullable()->unique();
            $table->string('host_type')->nullable();
            $table->string('host_id')->nullable();
            $table->string('group')->nullable();
            $table->string('kind');
            $table->unique(['host_type', 'host_id', 'group']);
            $table->string('owner_key')->nullable()->index();
            $table->json('cache_keys')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'owner_key', 'released_at', 'created_at'], 'tgs_canvases_unclaimed_index');
        });
        Schema::create('tgs_block_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('block_id', 26);
            $table->foreign('block_id')->references('id')->on('tgs_blocks')->cascadeOnDelete();
            $table->json('data');
            // External author: configurable Eloquent relation, no dependency on a site's users table.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['block_id', 'created_at']);
        });
        Schema::create('tgs_block_deletions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('canvas_id')->constrained('tgs_canvases')->cascadeOnDelete();
            $table->string('block_id', 26);
            $table->foreign('block_id')->references('id')->on('tgs_blocks')->cascadeOnDelete();
            $table->string('transaction_id', 26);
            $table->json('payload');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('canvas_id');
            $table->index('block_id');
        });
        Schema::create('tgs_collections', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('provisional_at')->nullable();
            $table->timestamps();
        });
        Schema::create('tgs_canvas_claim_locks', fn (Blueprint $table) => $table->string('owner_key')->primary());
    }

    public function down(): void
    {
        foreach (['tgs_canvas_claim_locks', 'tgs_collections', 'tgs_block_deletions', 'tgs_block_versions', 'tgs_canvases', 'tgs_block_media', 'tgs_block_placements', 'tgs_blocks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
