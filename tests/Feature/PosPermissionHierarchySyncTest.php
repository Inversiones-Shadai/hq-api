<?php

namespace Tests\Feature;

use App\Services\Headquarters\SyncPublicationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosPermissionHierarchySyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function change(string $entity, int $id, array $payload, int $version = 1): int
    {
        return DB::table('sync_changes')->insertGetId(['uuid' => (string) Str::uuid(), 'entity' => $entity,
            'entity_id' => $id, 'action' => 'UPDATE', 'version' => $version, 'schema_version' => 1,
            'payload' => json_encode(['id' => $id] + $payload, JSON_THROW_ON_ERROR), 'content_hash' => hash('sha256', $entity.$id.$version.json_encode($payload)), 'created_at' => now()]);
    }

    private function parents(): void
    {
        $this->change('rolespos', 5, ['nombre' => 'TEST rol', 'nivel' => 0, 'activo' => true]);
        $this->change('modulospos', 1, ['codigo' => 'configuracion', 'nombre' => 'TEST módulo', 'activo' => true]);
        $this->change('vistaspos', 2, ['modulo_id' => 1, 'codigo' => 'configuracion.establecimiento', 'nombre' => 'TEST vista', 'activo' => true]);
        $this->change('botonespos', 3, ['vista_id' => 2, 'codigo' => 'configurar', 'nombre' => 'TEST botón', 'activo' => true]);
    }

    public function test_publishes_grant_after_its_parents_even_when_grant_commits_first(): void
    {
        $grant = $this->change('permisospos', 4, ['rol_id' => 5, 'modulo_id' => 1, 'vista_id' => 2, 'boton_id' => 3, 'activo' => true]);
        $this->parents();

        app(SyncPublicationService::class)->publish();

        $this->assertDatabaseCount('sync_publications', 5);
        $this->assertSame($grant, (int) DB::table('sync_publications')->orderByDesc('sequence')->value('sync_change_id'));
    }

    public function test_user_grant_waits_for_global_user_and_matching_hierarchy(): void
    {
        $this->parents();
        $grant = ['usuario' => 91, 'modulo' => 1, 'vista' => 2, 'actionvista' => 3, 'tipo_tiempo' => null, 'lifetime' => null];
        $waiting = $this->change('permisosusuariospos', 6, $grant);
        app(SyncPublicationService::class)->publish();
        $this->assertDatabaseMissing('sync_publications', ['sync_change_id' => $waiting]);
        $this->change('usuariospos', 91, ['rol_id' => 5, 'nombre' => base64_encode('TEST user'), 'activo' => true, 'credenciales' => []]);
        app(SyncPublicationService::class)->publish();
        $this->assertDatabaseHas('sync_publications', ['sync_change_id' => $waiting]);
        $wrongHierarchy = $this->change('permisosusuariospos', 6, [...$grant, 'modulo' => 9], 2);
        app(SyncPublicationService::class)->publish();
        $this->assertDatabaseMissing('sync_publications', ['sync_change_id' => $wrongHierarchy]);
        $fixed = $this->change('permisosusuariospos', 6, $grant, 3);
        app(SyncPublicationService::class)->publish();
        $this->assertDatabaseHas('sync_publications', ['sync_change_id' => $fixed]);
    }

    public function test_holds_crossed_hierarchy_until_corrected_version_and_publishes_revocation(): void
    {
        $this->parents();
        $this->change('modulospos', 9, ['codigo' => 'inventario', 'nombre' => 'TEST otro módulo', 'activo' => true]);
        $bad = $this->change('permisospos', 4, ['rol_id' => 5, 'modulo_id' => 9, 'vista_id' => 2, 'boton_id' => 3, 'activo' => true]);

        app(SyncPublicationService::class)->publish();

        $this->assertDatabaseMissing('sync_publications', ['sync_change_id' => $bad]);
        $fixed = $this->change('permisospos', 4, ['rol_id' => 5, 'modulo_id' => 1, 'vista_id' => 2, 'boton_id' => 3, 'activo' => false], 2);
        app(SyncPublicationService::class)->publish();
        $this->assertDatabaseHas('sync_publications', ['sync_change_id' => $fixed]);
    }
}
