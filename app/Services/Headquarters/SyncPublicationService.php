<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SyncPublicationService
{
    public function publish(): object
    {
        return DB::transaction(function (): object {
            // All publication and future retention code must lock this row until commit.
            $state = DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            if (! $state) {
                throw new HeadquartersException('El flujo de sincronización no está inicializado.', 503);
            }
            $ranks = array_flip(EntityContract::orderedEntities());
            $rank = 'CASE changes.entity '.implode(' ', array_map(fn (string $entity, int $position): string => "WHEN '$entity' THEN $position", array_keys($ranks), $ranks)).' ELSE 1000 END';
            // Keep image payloads out of the full-ledger dependency scan.
            $changes = DB::table('sync_changes as changes')
                ->leftJoin('sync_publications as publications', 'publications.sync_change_id', '=', 'changes.id')
                ->whereNull('publications.sync_change_id')->select('changes.id')
                ->orderByRaw("CASE WHEN changes.action = 'DELETE' THEN 1 ELSE 0 END")
                ->orderByRaw("CASE WHEN changes.action = 'DELETE' THEN -($rank) ELSE ($rank) END")
                ->orderBy('changes.id')->cursor();
            $sequence = (int) $state->published_sequence;
            $rows = [];
            $published = [];
            $latest = function (string $entity, int $id) use (&$published): ?object {
                $key = $entity.':'.$id;
                if (! array_key_exists($key, $published)) {
                    $published[$key] = DB::table('sync_changes as changes')
                        ->join('sync_publications as publications', 'publications.sync_change_id', '=', 'changes.id')
                        ->where('changes.entity', $entity)->where('changes.entity_id', $id)
                        ->orderByDesc('changes.version')->select('changes.entity', 'changes.entity_id', 'changes.version', 'changes.action', 'changes.payload')->first();
                }

                return $published[$key];
            };
            foreach ($changes as $candidate) {
                $change = DB::table('sync_changes')->where('id', $candidate->id)->firstOrFail();
                $own = $latest($change->entity, (int) $change->entity_id);
                $ready = true;
                if ($change->action === 'DELETE' && (! $own || (int) $own->version < (int) $change->version)) {
                    $children = array_keys(array_filter(EntityContract::DEPENDENCIES, fn (array $parents): bool => isset($parents[$change->entity])));
                    if ($children !== []) {
                        $dependents = DB::table('sync_changes as child')
                            ->join('sync_publications as child_publication', 'child_publication.sync_change_id', '=', 'child.id')
                            ->whereIn('child.entity', $children)->where('child.action', '!=', 'DELETE')
                            ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from('sync_changes as newer')
                                ->join('sync_publications as newer_publication', 'newer_publication.sync_change_id', '=', 'newer.id')
                                ->whereColumn('newer.entity', 'child.entity')->whereColumn('newer.entity_id', 'child.entity_id')->whereColumn('newer.version', '>', 'child.version'))
                            ->select('child.entity', 'child.entity_id')->get();
                        foreach ($dependents as $dependent) {
                            $latest($dependent->entity, (int) $dependent->entity_id);
                        }
                        foreach ($published as $dependent) {
                            if (! $dependent || $dependent->action === 'DELETE' || ! in_array($dependent->entity, $children, true)) {
                                continue;
                            }
                            foreach (EntityContract::references($dependent->entity, json_decode($dependent->payload, true, 512, JSON_THROW_ON_ERROR)) as $reference) {
                                if ($reference['entity'] === $change->entity && $reference['entity_id'] === (int) $change->entity_id) {
                                    $ready = false;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                if ($change->action !== 'DELETE' && (! $own || (int) $own->version < (int) $change->version)) {
                    foreach (EntityContract::references($change->entity, json_decode($change->payload, true, 512, JSON_THROW_ON_ERROR)) as $reference) {
                        $parent = $latest($reference['entity'], $reference['entity_id']);
                        if (! $parent || $parent->action === 'DELETE') {
                            $ready = false;
                            break;
                        }
                    }
                }
                if ($ready && $change->entity === 'permisospos' && $change->action !== 'DELETE') {
                    $grant = json_decode($change->payload, true, 512, JSON_THROW_ON_ERROR);
                    $view = $latest('vistaspos', (int) $grant['vista_id']);
                    $button = $latest('botonespos', (int) $grant['boton_id']);
                    $viewData = $view ? json_decode($view->payload, true, 512, JSON_THROW_ON_ERROR) : [];
                    $buttonData = $button ? json_decode($button->payload, true, 512, JSON_THROW_ON_ERROR) : [];
                    $ready = (string) ($viewData['modulo_id'] ?? '') === (string) $grant['modulo_id']
                        && (string) ($buttonData['vista_id'] ?? '') === (string) $grant['vista_id'];
                }
                if ($ready && $change->entity === 'permisosusuariospos' && $change->action !== 'DELETE') {
                    $grant = json_decode($change->payload, true, 512, JSON_THROW_ON_ERROR);
                    $user = $latest('usuariospos', (int) $grant['usuario']);
                    $view = $latest('vistaspos', (int) $grant['vista']);
                    $button = $latest('botonespos', (int) $grant['actionvista']);
                    $v = $view ? json_decode($view->payload, true, 512, JSON_THROW_ON_ERROR) : [];
                    $b = $button ? json_decode($button->payload, true, 512, JSON_THROW_ON_ERROR) : [];
                    $ready = $user !== null && $user->action !== 'DELETE' && (string) ($v['modulo_id'] ?? '') === (string) $grant['modulo'] && (string) ($b['vista_id'] ?? '') === (string) $grant['vista'];
                }
                if (! $ready) {
                    continue;
                }
                if ($sequence >= PHP_INT_MAX) {
                    throw new HeadquartersException('Se agotó el rango de secuencias de sincronización.', 503);
                }
                $rows[] = ['sequence' => ++$sequence, 'sync_change_id' => $change->id, 'created_at' => now()];
                if (! $own || (int) $change->version > (int) $own->version) {
                    $published[$change->entity.':'.$change->entity_id] = $change;
                }
                if (count($rows) === 500) {
                    break;
                }
            }
            if ($rows !== []) {
                DB::table('sync_publications')->insert($rows);
                DB::table('sync_stream_state')->where('name', 'headquarters')->update([
                    'published_sequence' => $sequence, 'updated_at' => now(),
                ]);
                $state->published_sequence = $sequence;
            }

            // Scan unpublished rows each time, never use a source-ID high-water mark.
            // A lower ID committed later will receive a later publication sequence.
            return $state;
        });
    }
}
