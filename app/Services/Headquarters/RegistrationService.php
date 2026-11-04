<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use App\Models\HeadquartersDevice;
use App\Models\HeadquartersMatch;
use App\Models\SyncConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegistrationService
{
    public function requestRegistration(array $data): array
    {
        $binding = DB::table('source_device_links')->where('device_id', $data['source_device_id'])->first();
        if (! $binding || ! $binding->active || $binding->hq_client_uuid !== $data['hq_client_uuid'] || (int) $binding->stock_id !== (int) $data['stock_id']) {
            throw new HeadquartersException('El dispositivo no tiene un vínculo de stock autorizado.', 409);
        }
        if (HeadquartersDevice::where('source_device_id', $binding->device_id)->whereHas('matches', fn ($query) => $query->where('status', 'ACTIVE'))->exists()) {
            throw new HeadquartersException('El dispositivo ya tiene un hq-client autorizado. Revóquelo antes de sustituirlo.', 409);
        }
        $key = bin2hex(random_bytes(32));
        $code = sprintf('%06d', random_int(0, 999999));
        $message = 'headquarters-v1|'.$data['name'].'|'.$data['ip'];
        $match = HeadquartersMatch::create([
            'uuid' => (string) Str::uuid(), 'name' => $data['name'], 'ip' => $data['ip'], 'stock_id' => $data['stock_id'] ?? null,
            'client_id' => hash_hmac('sha256', $message, $key), 'registration_key_hash' => hash('sha256', $key),
            'approval_code_hash' => Hash::make($code), 'status' => HeadquartersMatch::STATUS_PENDING,
            'expires_at' => now()->addMinutes(config('headquarters.registration_minutes')),
            'source_device_id' => $binding->device_id,
        ]);

        return [
            'registration_id' => $match->uuid, 'registration_key' => $key, 'name' => $match->name,
            'ip' => $match->ip, 'expires_at' => $match->expires_at->toIso8601String(),
            'client_id_algorithm' => 'HMAC-SHA256', 'client_id_message' => $message,
            'approval_code' => $code, 'database_name' => LocalDatabaseName::forStock((int) $match->stock_id),
        ];
    }

    public function confirmRegistration(array $data): array
    {
        $result = DB::transaction(function () use ($data): array {
            DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $match = HeadquartersMatch::where('uuid', $data['registration_id'])->lockForUpdate()->first();
            if (! $match || ! hash_equals($match->registration_key_hash, hash('sha256', $data['registration_key']))
                || ! hash_equals($match->client_id, $data['client_id']) || $match->ip !== $data['ip']) {
                return ['error' => 'Registro o credenciales incorrectos.', 'status' => 401];
            }
            if ($match->status !== HeadquartersMatch::STATUS_PENDING) {
                return ['error' => 'La solicitud ya fue utilizada o revocada.', 'status' => 409];
            }
            $binding = DB::table('source_device_links')->where('device_id', $match->source_device_id)->first();
            if (! $binding || ! $binding->active || (int) $binding->stock_id !== (int) $match->stock_id) {
                return ['error' => 'El dispositivo cambió de estado o stock. Solicite un código nuevo.', 'status' => 409];
            }
            $existing = HeadquartersDevice::where('source_device_id', $binding->device_id)->first();
            if ($existing && $existing->matches()->where('status', 'ACTIVE')->exists()) {
                return ['error' => 'El dispositivo ya tiene un hq-client autorizado.', 'status' => 409];
            }
            if ($match->expires_at->lte(now()) || $match->attempts >= config('headquarters.max_code_attempts')) {
                $match->update(['status' => HeadquartersMatch::STATUS_REVOKED]);

                return ['error' => 'La solicitud venció o agotó sus intentos.', 'status' => 410];
            }
            if (! Hash::check($data['code'], $match->approval_code_hash)) {
                $match->attempts++;
                if ($match->attempts >= config('headquarters.max_code_attempts')) {
                    $match->status = HeadquartersMatch::STATUS_REVOKED;
                }
                $match->save();

                return ['error' => 'Código de autorización incorrecto.', 'status' => 401];
            }
            $device = HeadquartersDevice::updateOrCreate(['source_device_id' => $binding->device_id], [
                'uuid' => $binding->hq_client_uuid, 'client_id' => $match->client_id, 'name' => $match->name,
                'ip' => $match->ip, 'stock_id' => $match->stock_id, 'registered_at' => now(), 'status' => 'ACTIVO',
            ]);
            $consumer = SyncConsumer::updateOrCreate(['headquarters_device_id' => $device->id], [
                'uuid' => $device->uuid, 'name' => $device->name, 'stock_id' => $device->stock_id,
                'headquarters_device_id' => $device->id,
                'status' => 'ACTIVO', 'needs_bootstrap' => true,
                'scope_revision' => $existing ? ($existing->consumer?->scope_revision ?? 0) + 1 : 0,
            ]);
            $key = bin2hex(random_bytes(32));
            $match->update(['headquarters_device_id' => $device->id, 'key_hash' => hash('sha256', $key),
                'status' => HeadquartersMatch::STATUS_ACTIVE, 'confirmed_at' => now()]);

            return ['device' => $device, 'consumer_uuid' => $consumer->uuid, 'headquarters_key' => $key,
                'database_name' => LocalDatabaseName::forStock((int) $device->stock_id)];
        }, 3);
        if (isset($result['error'])) {
            throw new HeadquartersException($result['error'], $result['status']);
        }

        return $result;
    }

    public function revokeRequest(string $uuid): void
    {
        HeadquartersMatch::where('uuid', $uuid)->where('status', HeadquartersMatch::STATUS_PENDING)
            ->update(['status' => HeadquartersMatch::STATUS_REVOKED]);
    }

    public function revokeClient(string $uuid): void
    {
        DB::transaction(function () use ($uuid): void {
            DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $device = HeadquartersDevice::where('uuid', $uuid)->lockForUpdate()->first();
            if (! $device) {
                throw new HeadquartersException('Cliente no encontrado.', 404);
            }
            $device->update(['status' => 'INACTIVO']);
            SyncConsumer::where('headquarters_device_id', $device->id)->update(['status' => 'INACTIVO']);
            HeadquartersMatch::where('headquarters_device_id', $device->id)->update(['status' => 'REVOKED']);
        }, 3);
    }
}
