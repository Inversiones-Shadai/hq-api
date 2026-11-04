<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_device_links', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->integer('device_id')->primary();
            $table->uuid('hq_client_uuid')->unique();
            $table->integer('stock_id');
            $table->boolean('active');
            $table->unsignedBigInteger('version');
        });
        foreach (['headquarters_devices', 'headquarters_matches'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->integer('source_device_id')->nullable();
                $table->foreign('source_device_id')->references('device_id')->on('source_device_links')->restrictOnDelete();
                if ($name === 'headquarters_devices') {
                    $table->unique('source_device_id');
                }
            });
        }
        Schema::table('sync_consumers', function (Blueprint $table): void {
            $table->unsignedInteger('scope_revision')->default(0);
            $table->boolean('needs_bootstrap')->default(false);
        });
        foreach (['sync_batches', 'sync_bootstraps'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedInteger('scope_revision')->default(0));
        }
        Schema::table('sync_changes', function (Blueprint $table): void {
            $table->json('stock_ids')->nullable();
            $table->json('removed_stock_ids')->nullable();
        });
        Schema::table('sync_entity_states', fn (Blueprint $table) => $table->json('stock_ids')->nullable());
        DB::table('headquarters_devices')->whereNull('source_device_id')->update(['status' => 'INACTIVO']);
    }

    public function down(): void
    {
        Schema::table('sync_entity_states', fn (Blueprint $table) => $table->dropColumn('stock_ids'));
        Schema::table('sync_changes', fn (Blueprint $table) => $table->dropColumn(['stock_ids', 'removed_stock_ids']));
        foreach (['sync_batches', 'sync_bootstraps'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('scope_revision'));
        }
        Schema::table('sync_consumers', fn (Blueprint $table) => $table->dropColumn(['scope_revision', 'needs_bootstrap']));
        foreach (['headquarters_matches', 'headquarters_devices'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropForeign(['source_device_id']);
                if ($name === 'headquarters_devices') {
                    $table->dropUnique(['source_device_id']);
                }
                $table->dropColumn('source_device_id');
            });
        }
        Schema::drop('source_device_links');
    }
};
