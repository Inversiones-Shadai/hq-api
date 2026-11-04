<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use App\Models\SyncChange;
use App\Models\SyncConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncDeliveryService
{
    public function __construct(private readonly SyncPublicationService $publisher, private readonly StockScopeService $scope) {}

    public function download(SyncConsumer $authenticatedConsumer, int $limit): array
    {
        return DB::transaction(function () use ($authenticatedConsumer, $limit): array {
            $state = $this->publisher->publish();
            $consumer = $this->scope->consumer($authenticatedConsumer);
            if ($consumer->needs_bootstrap) {
                throw new HeadquartersException('Se requiere sincronización inicial para este cliente y su stock actual.', 409);
            }
            $this->assertCursorAvailable($consumer, $state);
            $changes = SyncChange::query()
                ->join('sync_publications as publication', 'publication.sync_change_id', '=', 'sync_changes.id')
                ->where('publication.sequence', '>', $consumer->last_sync_sequence)
                ->where('publication.sequence', '<=', $state->published_sequence)
                ->select(['sync_changes.*', 'publication.sequence as sync_sequence'])
                ->orderBy('publication.sequence')->limit($limit)->get();
            $to = $changes->isEmpty() ? $consumer->last_sync_sequence : (int) $changes->last()->sync_sequence;
            $batchUuid = null;
            if ($changes->isNotEmpty()) {
                $batchUuid = (string) Str::uuid();
                DB::table('sync_batches')->insert([
                    'uuid' => $batchUuid, 'sync_consumer_id' => $consumer->id,
                    'from_sequence' => $consumer->last_sync_sequence, 'to_sequence' => $to,
                    'expires_at' => now()->addMinutes(30), 'created_at' => now(),
                    'scope_revision' => $consumer->scope_revision,
                ]);
            }

            return [
                'consumer_uuid' => $consumer->uuid, 'batch_id' => $batchUuid,
                'from_sequence' => (string) $consumer->last_sync_sequence, 'to_sequence' => (string) $to,
                'published_sequence' => (string) $state->published_sequence,
                'has_more' => $to < (int) $state->published_sequence,
                'changes' => $changes->filter(fn (SyncChange $change): bool => ! in_array($change->entity, EntityContract::INTERNAL, true)
                    && (! in_array($change->entity, EntityContract::SCOPED, true) || in_array($consumer->stock_id, $change->stock_ids ?? [], true)
                        || in_array($consumer->stock_id, $change->removed_stock_ids ?? [], true)
                        || ($change->entity === 'catalogospos' && ($change->stock_ids ?? []) === [EntityContract::GLOBAL_DISCOUNT_SCOPE])))
                    ->map(fn (SyncChange $change): array => [
                        'sequence' => (string) $change->sync_sequence, 'uuid' => $change->uuid,
                        'entity' => $change->entity, 'entity_id' => (string) $change->entity_id,
                        'action' => in_array($consumer->stock_id, $change->removed_stock_ids ?? [], true) ? 'DELETE' : $change->action, 'version' => (string) $change->version,
                        'schema_version' => $change->schema_version, 'payload' => in_array($consumer->stock_id, $change->removed_stock_ids ?? [], true) ? null : $change->payload,
                        'created_at' => $change->created_at->toIso8601String(),
                    ])->values()->all(),
            ];
        }, 3);
    }

    public function acknowledge(SyncConsumer $authenticatedConsumer, string $batchUuid): array
    {
        return DB::transaction(function () use ($authenticatedConsumer, $batchUuid): array {
            $state = DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $consumer = $this->scope->consumer($authenticatedConsumer);
            if ($consumer->needs_bootstrap) {
                throw new HeadquartersException('Se requiere una nueva sincronización inicial.', 409);
            }
            if (! $state) {
                throw new HeadquartersException('El flujo de sincronización no está inicializado.', 503);
            }
            $this->assertCursorAvailable($consumer, $state);
            $batch = DB::table('sync_batches')->where('uuid', $batchUuid)
                ->where('sync_consumer_id', $consumer->id)->lockForUpdate()->first();
            if (! $batch) {
                throw new HeadquartersException('Lote no encontrado para este Headquarters.', 404);
            }
            if ((int) $batch->scope_revision !== $consumer->scope_revision) {
                throw new HeadquartersException('El lote pertenece a un stock anterior del dispositivo.', 409);
            }
            if ($batch->confirmed_at === null) {
                if (now()->gte($batch->expires_at)) {
                    throw new HeadquartersException('El lote venció. Descargue nuevamente desde el cursor confirmado.', 410);
                }
                if ((int) $batch->from_sequence > $consumer->last_sync_sequence
                    || (int) $batch->to_sequence > (int) $state->published_sequence) {
                    throw new HeadquartersException('El lote no corresponde al cursor confirmado.', 409);
                }
                $consumer->update(['last_sync_sequence' => max($consumer->last_sync_sequence, (int) $batch->to_sequence)]);
                DB::table('sync_batches')->where('uuid', $batchUuid)->update(['confirmed_at' => now()]);
            }

            return ['consumer_uuid' => $consumer->uuid, 'last_sync_sequence' => (string) $consumer->last_sync_sequence];
        }, 3);
    }

    private function assertCursorAvailable(SyncConsumer $consumer, object $state): void
    {
        if ($consumer->last_sync_sequence < (int) $state->retention_floor) {
            throw new HeadquartersException('Cursor vencido. Se requiere una nueva sincronización inicial.', 410);
        }
        if ($consumer->last_sync_sequence > (int) $state->published_sequence) {
            throw new HeadquartersException('Cursor de sincronización inconsistente.', 409);
        }
    }
}
