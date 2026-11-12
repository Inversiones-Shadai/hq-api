<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Exceptions\HeadquartersException;
use App\Http\Controllers\Controller;
use App\Services\Headquarters\InventoryMatrixClient;
use App\Services\Headquarters\StockScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryMatrixController extends Controller
{
    public function handle(Request $request, StockScopeService $scope, InventoryMatrixClient $client): JsonResponse
    {
        return $this->proxy($request, $scope, $client, 'guardar');
    }

    public function branches(Request $request, StockScopeService $scope, InventoryMatrixClient $client): JsonResponse
    {
        return $this->proxy($request, $scope, $client, 'sucursales');
    }

    public function lostSales(Request $request, StockScopeService $scope, InventoryMatrixClient $client): JsonResponse
    {
        return $this->proxy($request, $scope, $client, 'ventas-perdidas');
    }

    private function proxy(Request $request, StockScopeService $scope, InventoryMatrixClient $client, string $action): JsonResponse
    {
        $payload = $request->validate(['payload' => ['present', 'array'], 'device_id' => ['prohibited'], 'stock_id' => ['prohibited']])['payload'];
        $binding = DB::transaction(function () use ($request, $scope): object {
            DB::table('sync_stream_state')->where('name', 'headquarters')->lockForUpdate()->first();
            $consumer = $scope->consumer($request->attributes->get('headquarters_consumer'));
            $device = DB::table('headquarters_devices')->where('id', $consumer->headquarters_device_id)->first();

            return DB::table('source_device_links')->where('device_id', $device->source_device_id)->first();
        }, 3);
        if ($action === 'ventas-perdidas' && ($payload['stock_id'] ?? null) !== (string) $binding->stock_id) {
            throw new HeadquartersException('El stock del registro no coincide con la sucursal autorizada.', 403);
        }
        $result = $client->send($action, ['device_id' => $binding->device_id, 'client_uuid' => $binding->hq_client_uuid, 'payload' => $payload]);

        return $this->sendResponse($result);
    }
}
