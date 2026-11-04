<?php

namespace Database\Factories;

use App\Models\HeadquartersDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SyncConsumerFactory extends Factory
{
    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'name' => fake()->bothify('HQ-####'), 'headquarters_device_id' => HeadquartersDevice::factory(),
            'last_sync_sequence' => 0, 'status' => 'ACTIVO'];
    }
}
