<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use App\Models\SyncConsumer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class StockScopeService
{
    /** The stream-state row must be locked before locking a consumer. */
    public function consumer(SyncConsumer $consumer): SyncConsumer
    {
        $consumer = SyncConsumer::whereKey($consumer->id)->lockForUpdate()->firstOrFail();
        $device = DB::table('headquarters_devices')->where('id', $consumer->headquarters_device_id)->first();
        $link = $device ? DB::table('source_device_links')->where('device_id', $device->source_device_id)->first() : null;
        if ($consumer->status !== 'ACTIVO' || ! $device || $device->status !== 'ACTIVO' || ! $link || ! $link->active
            || (int) $link->stock_id !== (int) $consumer->stock_id) {
            throw new HeadquartersException('Cliente sin dispositivo activo o stock autorizado.', 401);
        }

        return $consumer;
    }

    public function states(SyncConsumer $consumer): Builder
    {
        return DB::table('sync_entity_states')->whereNotIn('entity', EntityContract::INTERNAL)
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('sync_changes as changes')
                ->join('sync_publications as publications', 'publications.sync_change_id', '=', 'changes.id')
                ->whereColumn('changes.entity', 'sync_entity_states.entity')->whereColumn('changes.entity_id', 'sync_entity_states.entity_id')
                ->whereColumn('changes.version', 'sync_entity_states.version'))
            ->where(fn (Builder $query) => $query->whereNotIn('entity', EntityContract::SCOPED)->orWhereJsonContains('stock_ids', $consumer->stock_id)
                ->orWhere(fn (Builder $global) => $global->where('entity', 'catalogospos')->whereJsonContains('stock_ids', EntityContract::GLOBAL_DISCOUNT_SCOPE)));
    }
}
