<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class HeadquartersDeviceFactory extends Factory
{
    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'client_id' => bin2hex(random_bytes(32)), 'name' => fake()->unique()->bothify('HQ-####'),
            'ip' => '127.0.0.1', 'status' => 'ACTIVO', 'registered_at' => now()];
    }
}
