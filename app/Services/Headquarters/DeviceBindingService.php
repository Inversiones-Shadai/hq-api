<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use Illuminate\Support\Facades\DB;

class DeviceBindingService
{
    /** Must run under the stream-state lock held by IngestService. */
    public function apply(array $change): void
    {
        $id = (int) $change['entity_id'];
        $binding = DB::table('source_device_links')->where('device_id', $id)->first();
        if ($binding && (int) $binding->version >= (int) $change['version']) {
            return;
        }
        if ($change['action'] === 'DELETE') {
            if ($binding) {
                DB::table('source_device_links')->where('device_id', $id)->update(['active' => false, 'version' => $change['version']]);
            }

            return;
        }
        $payload = $change['payload'];
        if ($binding && $binding->hq_client_uuid !== $payload['hq_client_uuid']) {
            throw new HeadquartersException('No se puede cambiar la identidad reservada del dispositivo.', 409);
        }
        $device = DB::table('headquarters_devices')->where('source_device_id', $id)->first();
        if ($device && (int) $device->stock_id !== (int) $payload['stock_id']) {
            $consumer = DB::table('sync_consumers')->where('headquarters_device_id', $device->id)->lockForUpdate()->first();
            DB::table('headquarters_devices')->where('id', $device->id)->update(['stock_id' => $payload['stock_id']]);
            if ($consumer) {
                DB::table('sync_consumers')->where('id', $consumer->id)->update(['stock_id' => $payload['stock_id'],
                    'needs_bootstrap' => true, 'scope_revision' => $consumer->scope_revision + 1]);
            }
        }
        DB::table('source_device_links')->updateOrInsert(['device_id' => $id], [
            'hq_client_uuid' => $payload['hq_client_uuid'], 'stock_id' => $payload['stock_id'],
            'active' => $payload['active'], 'version' => $change['version'],
        ]);
    }
}
