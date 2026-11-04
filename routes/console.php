<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('hq:status', function (): int {
    $this->info('Clientes: '.DB::table('headquarters_devices')->count().'; cambios recibidos: '.DB::table('sync_changes')->count().'; entidades: '.DB::table('sync_entity_states')->count());
    $this->info('Secuencia publicada: '.DB::table('sync_stream_state')->where('name', 'headquarters')->value('published_sequence'));
    $this->info('Pendientes de publicación: '.DB::table('sync_changes as changes')->leftJoin('sync_publications as publications', 'publications.sync_change_id', '=', 'changes.id')->whereNull('publications.sync_change_id')->count());
    $this->info('Dispositivos vinculados: '.DB::table('source_device_links')->count().'; transferencias vigentes: '.DB::table('sync_entity_states')->where('entity', 'transferencias')->where('deleted', false)->count().'; distribución vigente: '.DB::table('sync_entity_states')->where('entity', 'distribucion')->where('deleted', false)->count());

    return 0;
})->purpose('Mostrar el estado de Headquarters sin revelar claves');

Artisan::command('hq:cleanup', function (): int {
    DB::table('apishadai_request_nonces')->where('expires_at', '<', now()->subMinutes(5))->delete();
    DB::table('sync_batches')->where('expires_at', '<', now()->subDay())->delete();
    DB::table('sync_bootstraps')->where('expires_at', '<', now()->subDay())->delete();
    $this->info('Solicitudes y lotes temporales vencidos eliminados.');

    return 0;
})->purpose('Limpiar nonces y snapshots temporales vencidos');

Schedule::command('hq:cleanup')->hourly()->withoutOverlapping();
