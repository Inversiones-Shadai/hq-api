<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('headquarters_devices', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('client_id', 64)->unique();
            $table->string('name', 100);
            $table->string('ip', 45);
            $table->integer('stock_id')->nullable();
            $table->enum('status', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');
            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('headquarters_matches', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('ip', 45);
            $table->integer('stock_id')->nullable();
            $table->char('client_id', 64);
            $table->char('registration_key_hash', 64)->unique();
            $table->string('approval_code_hash');
            $table->char('key_hash', 64)->nullable()->unique();
            $table->foreignId('headquarters_device_id')->nullable()->constrained('headquarters_devices')->restrictOnDelete();
            $table->enum('status', ['PENDING', 'ACTIVE', 'REVOKED'])->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_consumers', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->integer('stock_id')->nullable();
            $table->unsignedBigInteger('last_sync_sequence')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->enum('status', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');
            $table->foreignId('headquarters_device_id')->unique()->constrained('headquarters_devices')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('sync_changes', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('entity', 100);
            $table->unsignedBigInteger('entity_id');
            $table->enum('action', ['CREATE', 'UPDATE', 'DELETE']);
            $table->unsignedBigInteger('version');
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('payload')->nullable();
            $table->char('content_hash', 64);
            $table->timestamp('created_at');
            $table->timestamp('received_at')->useCurrent();
            $table->unique(['entity', 'entity_id', 'version']);
            $table->index('created_at');
        });
        Schema::create('sync_stream_state', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('name', 50)->primary();
            $table->unsignedBigInteger('published_sequence')->default(0);
            $table->unsignedBigInteger('retention_floor')->default(0);
            $table->timestamps();
        });
        DB::table('sync_stream_state')->insert(['name' => 'headquarters', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('sync_publications', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('sequence')->primary();
            $table->foreignId('sync_change_id')->unique()->constrained('sync_changes')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('sync_batches', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('uuid')->primary();
            $table->foreignId('sync_consumer_id')->constrained('sync_consumers')->restrictOnDelete();
            $table->unsignedBigInteger('from_sequence');
            $table->unsignedBigInteger('to_sequence');
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('apishadai_request_nonces', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('nonce')->primary();
            $table->timestamp('expires_at')->index();
        });
        Schema::create('sync_entity_states', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('entity', 100);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('version');
            $table->unsignedInteger('schema_version');
            $table->json('payload')->nullable();
            $table->boolean('deleted');
            $table->primary(['entity', 'entity_id']);
        });
        Schema::create('sync_bootstraps', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('uuid')->primary();
            $table->foreignId('sync_consumer_id')->constrained('sync_consumers')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->unsignedInteger('pages');
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('sync_bootstrap_pages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('bootstrap_uuid');
            $table->unsignedInteger('page');
            $table->json('records');
            $table->primary(['bootstrap_uuid', 'page']);
            $table->foreign('bootstrap_uuid')->references('uuid')->on('sync_bootstraps')->cascadeOnDelete();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sync_changes ADD CONSTRAINT sync_changes_version_positive CHECK (version > 0)');
            DB::statement('ALTER TABLE sync_changes ADD CONSTRAINT sync_changes_schema_positive CHECK (schema_version > 0)');
        }
    }

    public function down(): void
    {
        foreach (['sync_bootstrap_pages', 'sync_bootstraps', 'sync_entity_states', 'apishadai_request_nonces',
            'sync_batches', 'sync_publications', 'sync_stream_state', 'sync_changes', 'sync_consumers',
            'headquarters_matches', 'headquarters_devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
