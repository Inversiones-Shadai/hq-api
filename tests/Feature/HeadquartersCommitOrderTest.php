<?php

namespace Tests\Feature;

use App\Services\Headquarters\SyncPublicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class HeadquartersCommitOrderTest extends TestCase
{
    public function test_headquarters_migration_can_be_rolled_back_and_reapplied(): void
    {
        if (DB::getDriverName() === 'mysql' && ! preg_match('/\Aapishadai_sync_test_[a-f0-9]+\z/', DB::connection()->getDatabaseName())) {
            $this->fail('Only a disposable database may be used.');
        }
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->artisan('migrate:rollback', ['--step' => 2, '--no-interaction' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('headquarters_devices'));
        $this->assertFalse(Schema::hasTable('sync_bootstrap_pages'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('sync_entity_states'));
        $this->assertSame(0, (int) DB::table('sync_stream_state')->where('name', 'headquarters')->value('published_sequence'));
    }

    public function test_mysql_commit_inversion_does_not_skip_a_late_lower_id(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the explicitly provisioned disposable MySQL database.');
        }
        $database = DB::connection()->getDatabaseName();
        if (! preg_match('/\Aapishadai_sync_test_[a-f0-9]+\z/', $database)) {
            $this->fail('Only a disposable database may be used.');
        }
        if (! Schema::hasTable('sync_changes')) {
            $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        }
        config(['database.connections.low_writer' => DB::connection()->getConfig()]);
        $writer = DB::connection('low_writer');
        try {
            $writer->beginTransaction();
            $low = $writer->table('sync_changes')->insertGetId($this->rawChange(1));
            $high = DB::table('sync_changes')->insertGetId($this->rawChange(2));
            $publisher = app(SyncPublicationService::class);
            $publisher->publish();
            $this->assertSame([$high], DB::table('sync_publications')->orderBy('sequence')->pluck('sync_change_id')->all());
            $writer->commit();
            $publisher->publish();
            $this->assertSame([$high, $low], DB::table('sync_publications')->orderBy('sequence')->pluck('sync_change_id')->all());
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            DB::purge('low_writer');
            DB::table('sync_publications')->delete();
            DB::table('sync_changes')->delete();
            DB::table('sync_stream_state')->where('name', 'headquarters')->update(['published_sequence' => 0]);
        }
    }

    private function rawChange(int $id): array
    {
        return ['uuid' => (string) Str::uuid(), 'entity' => 'prod_categorias', 'entity_id' => $id, 'action' => 'CREATE', 'version' => 1,
            'schema_version' => 1, 'payload' => json_encode(['id' => $id, 'nombre' => 'Test'], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'received_at' => now(), 'content_hash' => hash('sha256', (string) $id)];
    }
}
