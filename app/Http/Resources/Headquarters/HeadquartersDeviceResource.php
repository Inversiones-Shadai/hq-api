<?php

namespace App\Http\Resources\Headquarters;

use App\Services\Headquarters\LocalDatabaseName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HeadquartersDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid, 'client_id' => $this->client_id, 'name' => $this->name,
            'stock_id' => $this->stock_id, 'status' => $this->status,
            'database_name' => LocalDatabaseName::forStock((int) $this->stock_id),
            'device_id' => $this->source_device_id,
            'registered_at' => $this->registered_at?->toIso8601String(),
        ];
    }
}
