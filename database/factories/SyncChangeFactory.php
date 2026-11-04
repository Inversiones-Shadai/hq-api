<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SyncChangeFactory extends Factory
{
    public function definition(): array
    {
        $id = fake()->unique()->numberBetween(1, 999999);

        return ['uuid' => (string) Str::uuid(), 'entity' => 'prod_categorias', 'entity_id' => $id, 'action' => 'CREATE',
            'version' => 1, 'schema_version' => 1, 'payload' => ['id' => $id, 'nombre' => fake()->word()],
            'content_hash' => hash('sha256', (string) $id), 'created_at' => now(), 'received_at' => now()];
    }
}
