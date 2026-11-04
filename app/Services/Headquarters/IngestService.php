<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IngestService
{
    public function __construct(private readonly SyncPublicationService $publisher, private readonly DeviceBindingService $bindings) {}

    /** @param list<array<string, mixed>> $changes */
    public function ingest(array $changes): array
    {
        return DB::transaction(function () use ($changes): array {
            DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $accepted = [];
            foreach ($changes as $change) {
                $change['entity_id'] = (string) $change['entity_id'];
                $change['version'] = (string) $change['version'];
                $change['schema_version'] = (int) $change['schema_version'];
                if (in_array($change['entity'], EntityContract::SCOPED, true)) {
                    $change['stock_ids'] = array_map('intval', $change['stock_ids']);
                    $change['removed_stock_ids'] = array_map('intval', $change['removed_stock_ids'] ?? []);
                    sort($change['stock_ids']);
                    sort($change['removed_stock_ids']);
                } else {
                    unset($change['stock_ids'], $change['removed_stock_ids']);
                }
                $contentHash = hash('sha256', json_encode($this->canonicalize($change), JSON_THROW_ON_ERROR));
                $existing = DB::table('sync_changes')->where('uuid', $change['uuid'])->first();
                if ($existing) {
                    if (! hash_equals($existing->content_hash, $contentHash)) {
                        throw new HeadquartersException('UUID reutilizado con contenido diferente.', 409);
                    }
                    $accepted[] = $change['uuid'];

                    continue;
                }
                if (DB::table('sync_changes')->where('entity', $change['entity'])->where('entity_id', $change['entity_id'])->where('version', $change['version'])->exists()) {
                    throw new HeadquartersException('Versión de entidad ya recibida con otro UUID.', 409);
                }
                DB::table('sync_changes')->insert([
                    ...$change, 'payload' => $change['payload'] === null ? null : json_encode($change['payload'], JSON_THROW_ON_ERROR),
                    'stock_ids' => json_encode($change['stock_ids'] ?? [], JSON_THROW_ON_ERROR),
                    'removed_stock_ids' => json_encode($change['removed_stock_ids'] ?? [], JSON_THROW_ON_ERROR),
                    'created_at' => Carbon::parse($change['created_at'])->utc(), 'content_hash' => $contentHash, 'received_at' => now(),
                ]);
                $state = DB::table('sync_entity_states')->where('entity', $change['entity'])->where('entity_id', $change['entity_id'])->first();
                if (! $state || (int) $change['version'] > (int) $state->version) {
                    if ($change['entity'] === 'devices') {
                        $this->bindings->apply($change);
                    }
                    DB::table('sync_entity_states')->updateOrInsert(
                        ['entity' => $change['entity'], 'entity_id' => $change['entity_id']],
                        ['version' => $change['version'], 'schema_version' => $change['schema_version'],
                            'payload' => $change['payload'] === null ? null : json_encode($change['payload'], JSON_THROW_ON_ERROR), 'deleted' => $change['action'] === 'DELETE',
                            'stock_ids' => json_encode($change['stock_ids'] ?? [], JSON_THROW_ON_ERROR)],
                    );
                }
                $accepted[] = $change['uuid'];
            }
            $state = $this->publisher->publish();

            return ['accepted' => $accepted, 'published_sequence' => (string) $state->published_sequence];
        }, 3);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->canonicalize(...), $value);
        }

        return $value;
    }
}
