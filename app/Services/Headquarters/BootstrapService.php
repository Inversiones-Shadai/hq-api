<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use App\Models\SyncConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapService
{
    private const PAGE_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly SyncPublicationService $publisher, private readonly StockScopeService $scope) {}

    public function create(SyncConsumer $consumer): array
    {
        return DB::transaction(function () use ($consumer): array {
            $state = $this->publisher->publish();
            $consumer = $this->scope->consumer($consumer);
            $uuid = (string) Str::uuid();
            $expires = now()->addHour();
            DB::table('sync_bootstraps')->insert(['uuid' => $uuid, 'sync_consumer_id' => $consumer->id, 'sequence' => $state->published_sequence,
                'pages' => 0, 'expires_at' => $expires, 'created_at' => now(), 'scope_revision' => $consumer->scope_revision]);
            $page = 0;
            $items = [];
            $bytes = 0;
            $available = [];
            $flush = function () use ($uuid, &$page, &$items, &$bytes): void {
                if ($items !== []) {
                    DB::table('sync_bootstrap_pages')->insert(['bootstrap_uuid' => $uuid, 'page' => ++$page, 'records' => json_encode(['change_ids' => $items], JSON_THROW_ON_ERROR)]);
                    $items = [];
                    $bytes = 0;
                }
            };
            foreach (EntityContract::orderedEntities() as $entity) {
                $fields = $entity === 'productos' ? ['producto_padre_id'] : [];
                foreach (EntityContract::DEPENDENCIES[$entity] ?? [] as $paths) {
                    foreach ($paths as $path) {
                        $fields[] = explode('.', $path)[0];
                    }
                }
                $references = array_map(fn (string $field): string => "'$field', JSON_EXTRACT(payload, '$.$field')", array_unique($fields));
                $size = DB::getDriverName() === 'mysql' ? 'JSON_STORAGE_SIZE(payload)' : 'LENGTH(CAST(payload AS BLOB))';
                $this->scope->states($consumer)
                    ->where('sync_entity_states.entity', $entity)
                    ->select('sync_entity_states.entity', 'sync_entity_states.entity_id', 'sync_entity_states.deleted')
                    ->selectSub(DB::table('sync_changes')->select('id')->whereColumn('entity', 'sync_entity_states.entity')
                        ->whereColumn('entity_id', 'sync_entity_states.entity_id')->whereColumn('version', 'sync_entity_states.version'), 'change_id')
                    ->selectRaw('JSON_OBJECT('.implode(', ', $references).") AS payload, $size AS payload_bytes")
                    ->chunkById(500, function ($records) use (&$items, &$bytes, &$available, $flush): void {
                        foreach ($records as $record) {
                            $payload = $record->payload === null ? null : json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
                            if (! $record->deleted) {
                                foreach (EntityContract::references($record->entity, $payload) as $reference) {
                                    if (! isset($available[$reference['entity'].':'.$reference['entity_id']])) {
                                        throw new HeadquartersException('Hay dependencias pendientes de publicación. Reintente la sincronización inicial.', 503);
                                    }
                                }
                                $available[$record->entity.':'.$record->entity_id] = true;
                            }
                            // Allow for JSON escaping and envelope fields without loading images into PHP.
                            $recordBytes = 3 * (int) $record->payload_bytes + 512;
                            if ($items !== [] && $bytes + $recordBytes > self::PAGE_BYTES) {
                                $flush();
                            }
                            $items[] = (string) $record->change_id;
                            $bytes += $recordBytes;
                            if (count($items) === 500) {
                                $flush();
                            }
                        }
                    }, 'sync_entity_states.entity_id', 'entity_id');
            }
            $flush();
            DB::table('sync_bootstraps')->where('uuid', $uuid)->update(['pages' => $page]);

            return ['bootstrap_id' => $uuid, 'sequence' => (string) $state->published_sequence, 'pages' => $page, 'expires_at' => $expires->toIso8601String()];
        }, 3);
    }

    public function page(SyncConsumer $consumer, string $uuid, int $page): array
    {
        return DB::transaction(function () use ($consumer, $uuid, $page): array {
            DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $consumer = $this->scope->consumer($consumer);
            $bootstrap = $this->find($consumer, $uuid);
            $record = DB::table('sync_bootstrap_pages')->where('bootstrap_uuid', $uuid)->where('page', $page)->first();
            if (! $record) {
                throw new HeadquartersException('Página no encontrada.', 404);
            }

            return ['bootstrap_id' => $uuid, 'sequence' => (string) $bootstrap->sequence, 'page' => $page, 'pages' => (int) $bootstrap->pages,
                'records' => $this->records($record->records)];
        }, 3);
    }

    public function acknowledge(SyncConsumer $consumer, string $uuid): array
    {
        return DB::transaction(function () use ($consumer, $uuid): array {
            DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $consumer = $this->scope->consumer($consumer);
            $bootstrap = $this->find($consumer, $uuid);
            if ($bootstrap->confirmed_at === null) {
                $consumer->update(['last_sync_sequence' => max($consumer->last_sync_sequence, (int) $bootstrap->sequence), 'needs_bootstrap' => false]);
                DB::table('sync_bootstraps')->where('uuid', $uuid)->update(['confirmed_at' => now()]);
            }

            return ['consumer_uuid' => $consumer->uuid, 'last_sync_sequence' => (string) $consumer->last_sync_sequence];
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    private function records(string $stored): array
    {
        $manifest = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        if (array_is_list($manifest)) {
            return $manifest;
        }
        // Published changes are immutable; a newer catalog version cannot alter this snapshot.
        $changes = DB::table('sync_changes')->whereIn('id', $manifest['change_ids'])->get()->keyBy('id');

        return array_map(function (string $id) use ($changes): array {
            $change = $changes->get($id);
            if (! $change) {
                throw new HeadquartersException('La copia inicial ya no está disponible. Solicite una nueva.', 410);
            }

            return ['entity' => $change->entity, 'entity_id' => (string) $change->entity_id, 'version' => (string) $change->version,
                'schema_version' => (int) $change->schema_version, 'deleted' => $change->action === 'DELETE',
                'payload' => $change->payload === null ? null : json_decode($change->payload, true, 512, JSON_THROW_ON_ERROR)];
        }, $manifest['change_ids']);
    }

    private function find(SyncConsumer $consumer, string $uuid): object
    {
        $bootstrap = DB::table('sync_bootstraps')->where('uuid', $uuid)->where('sync_consumer_id', $consumer->id)->first();
        if (! $bootstrap) {
            throw new HeadquartersException('Sincronización inicial no encontrada.', 404);
        }
        if ((int) $bootstrap->scope_revision !== $consumer->scope_revision) {
            throw new HeadquartersException('La instantánea pertenece a un stock anterior del dispositivo.', 409);
        }
        if (now()->gte($bootstrap->expires_at)) {
            throw new HeadquartersException('Sincronización inicial vencida.', 410);
        }

        return $bootstrap;
    }
}
