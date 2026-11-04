<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Exceptions\HeadquartersException;
use App\Http\Controllers\Controller;
use App\Services\Headquarters\StockScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function index(Request $request, StockScopeService $scope): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'], 'stock_id' => ['prohibited'], 'device_id' => ['prohibited']]);
        $result = DB::transaction(function () use ($request, $scope, $data): array {
            DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $consumer = $scope->consumer($request->attributes->get('headquarters_consumer'));
            $page = $scope->states($consumer)->where('entity', 'transferencias')->where('deleted', false)
                ->orderByDesc('entity_id')->paginate($data['per_page'] ?? 30);

            return ['stock_id' => $consumer->stock_id, 'items' => $page->getCollection()->map(fn (object $state): array => [
                'entity_id' => (string) $state->entity_id, 'version' => (string) $state->version,
                'payload' => json_decode($state->payload, true, 512, JSON_THROW_ON_ERROR),
            ])->all(), 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()];
        }, 3);

        return $this->sendResponse($result);
    }

    public function show(Request $request, StockScopeService $scope, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $scope, $id): array {
            DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $consumer = $scope->consumer($request->attributes->get('headquarters_consumer'));
            $state = $scope->states($consumer)->where('entity', 'transferencias')->where('entity_id', $id)->where('deleted', false)->first();
            if (! $state) {
                throw new HeadquartersException('Transferencia no encontrada para el stock del dispositivo.', 404);
            }

            return ['stock_id' => $consumer->stock_id, 'entity_id' => (string) $state->entity_id,
                'version' => (string) $state->version, 'payload' => json_decode($state->payload, true, 512, JSON_THROW_ON_ERROR)];
        }, 3);

        return $this->sendResponse($result);
    }
}
