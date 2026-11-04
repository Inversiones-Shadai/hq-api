<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class HeadquartersMatchFactory extends Factory
{
    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'client_id' => bin2hex(random_bytes(32)), 'name' => fake()->bothify('HQ-####'),
            'ip' => '127.0.0.1', 'registration_key_hash' => hash('sha256', random_bytes(32)), 'approval_code_hash' => Hash::make('123456'),
            'status' => 'PENDING', 'expires_at' => now()->addMinutes(10)];
    }
}
