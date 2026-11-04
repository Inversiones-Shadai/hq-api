<?php

namespace Tests\Feature;

use App\Models\HeadquartersDevice;
use App\Models\SyncConsumer;
use App\Services\Headquarters\BootstrapService;
use App\Services\Headquarters\EntityContract;
use App\Services\Headquarters\IngestService;
use App\Services\Headquarters\StockScopeService;
use App\Services\Headquarters\SyncDeliveryService;
use App\Services\Headquarters\SyncPublicationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class HeadquartersDependencyOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function change(string $entity, int $id = 1, array $fields = [], string $action = 'CREATE', int $version = 1): array
    {
        $payload = ['id' => $id, ...array_fill_keys(array_diff(EntityContract::FIELDS[$entity], ['id']), null), ...$fields];

        return ['uuid' => (string) Str::uuid(), 'entity' => $entity, 'entity_id' => (string) $id, 'action' => $action,
            'version' => (string) $version, 'schema_version' => 1, 'created_at' => now()->toIso8601String(),
            'payload' => $action === 'DELETE' ? null : $payload, ...($entity === 'transferencias' ? ['stock_ids' => [1, 2]] : [])];
    }

    private function chain(): array
    {
        $lot = ['id' => 1, 'producto' => 1, 'lote' => 'L-1', 'fecha_elab' => '2026-01-01', 'fecha_exp' => '2028-01-01',
            'venta' => ['distribucion_id' => '1', 'precio' => '20.0000', 'incluye_isv' => false,
                'tasa_isv' => '12.5000', 'tratamiento' => 'GRAVADO', 'dto1' => '0.0000', 'dto2' => '10.0000', 'dto3' => '20.0000']];

        return [
            $this->change('concentraciones', fields: ['valor' => '500mg']),
            $this->change('principal_activos', fields: ['nombre' => 'Paracetamol', 'concentracion' => 1]),
            $this->change('fam_presentacion', fields: ['descripcion' => 'Tableta']),
            $this->change('fam_administracion', fields: ['descripcion' => 'Oral']),
            $this->change('familia', fields: ['presentacion' => 1, 'administracion' => 1, 'descripcion' => 'Analgésicos']),
            $this->change('prod_categorias', fields: ['nombre' => 'Medicamentos']),
            $this->change('laboratorios', fields: ['nombre' => 'Laboratorio']),
            $this->change('prod_unidades', fields: ['abreviatura_c' => 'CJ', 'cantidad_c' => 10, 'abreviatura_v' => 'UND', 'cantidad_v' => 1]),
            $this->change('prod_estado', fields: ['descripcion' => 'ACTIVO']),
            $this->change('productos', fields: ['categoria' => 1, 'laboratorio' => 1, 'unidad' => 1, 'familia' => 1, 'estado' => 1, 'principios_activos' => [1], 'codigo' => 'P-1', 'descripcion' => 'Producto']),
            $this->change('distribucion', fields: ['lote' => 1, 'precio' => '20.00', 'lote_datos' => $lot]),
            $this->change('stock_estado', fields: ['descripcion' => 'ACTIVO']),
            $this->change('stocks', fields: ['estado' => 1, 'descripcion' => 'Origen']),
            $this->change('stocks', 2, ['estado' => 1, 'descripcion' => 'Destino']),
            $this->change('transferencias_tipo', fields: ['descripcion' => 'Traslado']),
            $this->change('transferencia_estado', fields: ['descripcion' => 'ENVIADA']),
            $this->change('transferencia_estado', 2, ['descripcion' => 'PENDIENTE']),
            $this->change('transferencias', fields: ['stock_de' => 1, 'stock_para' => 2, 'tipo' => 1, 'estado' => 1, 'estado_recepcion' => 2, 'detalles' => [['id' => 1, 'lote' => 1, 'cantidad' => 2, 'lote_datos' => $lot]]]),
        ];
    }

    private function consumer(): SyncConsumer
    {
        $uuid = (string) Str::uuid();
        DB::table('source_device_links')->insert(['device_id' => 42, 'hq_client_uuid' => $uuid, 'stock_id' => 1, 'active' => true, 'version' => 1]);
        $device = HeadquartersDevice::factory()->create(['uuid' => $uuid, 'source_device_id' => 42, 'stock_id' => 1]);

        return SyncConsumer::factory()->create(['headquarters_device_id' => $device->id, 'stock_id' => 1, 'last_sync_sequence' => 0, 'needs_bootstrap' => false]);
    }

    private function clientDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE records (entity TEXT, id INTEGER, PRIMARY KEY (entity,id))');
        $pdo->exec('CREATE TABLE links (entity TEXT,id INTEGER,parent_entity TEXT,parent_id INTEGER, FOREIGN KEY(entity,id) REFERENCES records(entity,id), FOREIGN KEY(parent_entity,parent_id) REFERENCES records(entity,id))');

        return $pdo;
    }

    private function apply(PDO $pdo, array $record): void
    {
        $pdo->prepare('DELETE FROM links WHERE entity=? AND id=?')->execute([$record['entity'], $record['entity_id']]);
        if (($record['action'] ?? '') === 'DELETE' || ($record['deleted'] ?? false)) {
            $pdo->prepare('DELETE FROM records WHERE entity=? AND id=?')->execute([$record['entity'], $record['entity_id']]);

            return;
        }
        $pdo->prepare('INSERT OR IGNORE INTO records (entity,id) VALUES (?,?)')->execute([$record['entity'], $record['entity_id']]);
        foreach (EntityContract::references($record['entity'], $record['payload']) as $reference) {
            $pdo->prepare('INSERT INTO links (entity,id,parent_entity,parent_id) VALUES (?,?,?,?)')
                ->execute([$record['entity'], $record['entity_id'], $reference['entity'], $reference['entity_id']]);
        }
    }

    public function test_customer_document_and_birth_date_reach_the_initial_snapshot_unchanged(): void
    {
        app(IngestService::class)->ingest([$this->change('clientepos', fields: [
            'nombre' => 'TEST paciente', 'rtn' => '0000199900001', 'tipo_documento' => 'IDENTIDAD', 'fecha_nacimiento' => '1946-10-12',
        ])]);
        app(SyncPublicationService::class)->publish();
        $consumer = $this->consumer();
        $service = app(BootstrapService::class);
        $snapshot = $service->create($consumer);
        $records = $service->page($consumer, $snapshot['bootstrap_id'], 1)['records'];
        $customer = collect($records)->firstWhere('entity', 'clientepos');
        $this->assertSame('IDENTIDAD', $customer['payload']['tipo_documento']);
        $this->assertSame('1946-10-12', $customer['payload']['fecha_nacimiento']);
        $this->assertSame('0000199900001', $customer['payload']['rtn']);
    }

    public function test_reverse_arrivals_can_be_applied_with_foreign_keys_in_batches_of_one(): void
    {
        foreach (array_reverse($this->chain()) as $change) {
            app(IngestService::class)->ingest([$change]);
        }
        $consumer = $this->consumer();
        $pdo = $this->clientDatabase();
        $delivery = app(SyncDeliveryService::class);
        $count = 0;
        do {
            $batch = $delivery->download($consumer, 1);
            foreach ($batch['changes'] as $change) {
                $this->apply($pdo, $change);
                $count++;
            }
            if ($batch['batch_id']) {
                $delivery->acknowledge($consumer, $batch['batch_id']);
            }
        } while ($batch['has_more']);
        $this->assertSame(count($this->chain()), $count);
        $this->assertSame($count, (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn());
        $this->assertSame($count, $consumer->fresh()->last_sync_sequence);
    }

    public function test_snapshot_keeps_dependencies_before_dependents_across_the_500_record_page_boundary(): void
    {
        $changes = array_reverse($this->chain());
        foreach (range(100, 600) as $id) {
            $changes[] = $this->change('prod_categorias', $id, ['nombre' => 'Categoría '.$id]);
        }
        app(IngestService::class)->ingest($changes);
        app(SyncPublicationService::class)->publish();
        $consumer = $this->consumer();
        $service = app(BootstrapService::class);
        $snapshot = $service->create($consumer);
        $this->assertSame(2, $snapshot['pages']);
        $pdo = $this->clientDatabase();
        for ($page = 1; $page <= $snapshot['pages']; $page++) {
            foreach ($service->page($consumer, $snapshot['bootstrap_id'], $page)['records'] as $record) {
                $this->apply($pdo, $record);
            }
        }
        $this->assertSame(count($changes), (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn());
        $service->acknowledge($consumer, $snapshot['bootstrap_id']);
        $this->assertSame((int) $snapshot['sequence'], $consumer->fresh()->last_sync_sequence);
    }

    public function test_snapshot_limits_image_pages_and_keeps_the_original_product_version(): void
    {
        $image = str_repeat('/9jA', 250000);
        foreach (range(1, 6) as $id) {
            app(IngestService::class)->ingest([$this->change('productos', $id, ['codigo' => 'P-'.$id, 'imagen_base64' => $image])]);
        }
        $consumer = $this->consumer();
        $service = app(BootstrapService::class);

        $snapshot = $service->create($consumer);

        $this->assertGreaterThan(1, $snapshot['pages']);
        $storedBytes = DB::table('sync_bootstrap_pages')->where('bootstrap_uuid', $snapshot['bootstrap_id'])
            ->selectRaw('SUM(LENGTH(records)) AS bytes')->value('bytes');
        $this->assertLessThan(10000, (int) $storedBytes);
        app(IngestService::class)->ingest([$this->change('productos', fields: ['codigo' => 'UPDATED', 'imagen_base64' => null], version: 2)]);
        $ids = [];
        for ($page = 1; $page <= $snapshot['pages']; $page++) {
            $data = $service->page($consumer, $snapshot['bootstrap_id'], $page);
            $this->assertLessThan(4 * 1024 * 1024, strlen(json_encode($data, JSON_THROW_ON_ERROR)));
            foreach ($data['records'] as $record) {
                $ids[] = $record['entity_id'];
                $this->assertSame('1', $record['version']);
                $this->assertSame('P-'.$record['entity_id'], $record['payload']['codigo']);
                $this->assertSame($image, $record['payload']['imagen_base64']);
            }
        }
        $this->assertSame(['1', '2', '3', '4', '5', '6'], $ids);
        $service->acknowledge($consumer, $snapshot['bootstrap_id']);
        $this->assertSame((int) $snapshot['sequence'], $consumer->fresh()->last_sync_sequence);
    }

    public function test_snapshot_pages_created_before_manifest_storage_are_still_readable(): void
    {
        app(IngestService::class)->ingest([$this->change('prod_categorias', fields: ['nombre' => 'Original'])]);
        $consumer = $this->consumer();
        $service = app(BootstrapService::class);
        $snapshot = $service->create($consumer);
        $records = $service->page($consumer, $snapshot['bootstrap_id'], 1)['records'];
        DB::table('sync_bootstrap_pages')->where('bootstrap_uuid', $snapshot['bootstrap_id'])
            ->update(['records' => json_encode($records, JSON_THROW_ON_ERROR)]);

        $this->assertSame($records, $service->page($consumer, $snapshot['bootstrap_id'], 1)['records']);
    }

    public function test_orphan_changes_stay_pending_without_blocking_roots_or_appearing_in_snapshots(): void
    {
        app(IngestService::class)->ingest([$this->change('productos', fields: ['principios_activos' => [1]])]);
        $this->assertDatabaseCount('sync_changes', 1);
        $this->assertDatabaseCount('sync_publications', 0);
        $consumer = $this->consumer();
        $this->assertSame(0, app(StockScopeService::class)->states($consumer)->count());
        $this->assertSame(0, app(BootstrapService::class)->create($consumer)['pages']);
        app(IngestService::class)->ingest([$this->change('prod_categorias', fields: ['nombre' => 'Independiente'])]);
        $this->assertDatabaseCount('sync_publications', 1);
        app(IngestService::class)->ingest([$this->change('principal_activos', fields: ['concentracion' => 1])]);
        $this->assertDatabaseCount('sync_publications', 1);
        app(IngestService::class)->ingest([$this->change('concentraciones', fields: ['valor' => '500mg'])]);
        $this->assertDatabaseCount('sync_publications', 4);
        $entities = DB::table('sync_publications as p')->join('sync_changes as c', 'c.id', '=', 'p.sync_change_id')->orderBy('p.sequence')->pluck('c.entity')->all();
        $this->assertSame(['prod_categorias', 'concentraciones', 'principal_activos', 'productos'], $entities);
    }

    public function test_retiring_a_sale_price_does_not_require_deleting_historical_transfers(): void
    {
        app(IngestService::class)->ingest($this->chain());
        $consumer = $this->consumer();
        $delivery = app(SyncDeliveryService::class);
        $initial = $delivery->download($consumer, 100);
        $delivery->acknowledge($consumer, $initial['batch_id']);
        app(IngestService::class)->ingest([$this->change('distribucion', action: 'DELETE', version: 2)]);
        $batch = $delivery->download($consumer, 100);
        $this->assertCount(1, $batch['changes']);
        $this->assertSame('distribucion', $batch['changes'][0]['entity']);
        $this->assertSame('DELETE', $batch['changes'][0]['action']);
        $delivery->acknowledge($consumer, $batch['batch_id']);
        $bootstrap = app(BootstrapService::class);
        $snapshot = $bootstrap->create($consumer);
        $records = $bootstrap->page($consumer, $snapshot['bootstrap_id'], 1)['records'];
        $transfers = array_values(array_filter($records, fn ($record) => $record['entity'] === 'transferencias'));
        $this->assertCount(1, $transfers);
        $this->assertSame('20.0000', $transfers[0]['payload']['detalles'][0]['lote_datos']['venta']['precio']);
    }

    public function test_deletes_are_delivered_children_first_so_client_foreign_keys_remain_valid(): void
    {
        app(IngestService::class)->ingest($this->chain());
        $consumer = $this->consumer();
        $delivery = app(SyncDeliveryService::class);
        $pdo = $this->clientDatabase();
        $initial = $delivery->download($consumer, 100);
        foreach ($initial['changes'] as $change) {
            $this->apply($pdo, $change);
        }
        $delivery->acknowledge($consumer, $initial['batch_id']);
        $deletes = array_map(fn (array $change): array => $this->change($change['entity'], (int) $change['entity_id'], action: 'DELETE', version: 2), $this->chain());
        foreach ($deletes as $delete) {
            app(IngestService::class)->ingest([$delete]);
        }
        $batch = $delivery->download($consumer, 100);
        foreach ($batch['changes'] as $change) {
            $this->apply($pdo, $change);
        }
        $this->assertCount(count($deletes), $batch['changes']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn());
    }
}
