<?php

use App\Http\Controllers\API\Headquarters\DesktopLicenseController;
use App\Http\Controllers\API\Headquarters\DesktopUpdateController;
use App\Http\Controllers\API\Headquarters\InternalController;
use App\Http\Controllers\API\Headquarters\InventoryControlController;
use App\Http\Controllers\API\Headquarters\InventoryMatrixController;
use App\Http\Controllers\API\Headquarters\PosAttendanceController;
use App\Http\Controllers\API\Headquarters\PosProductLotController;
use App\Http\Controllers\API\Headquarters\PosPurchaseController;
use App\Http\Controllers\API\Headquarters\PosTaskController;
use App\Http\Controllers\API\Headquarters\PosTransferController;
use App\Http\Controllers\API\Headquarters\SyncController;
use App\Http\Controllers\API\Headquarters\TransferController;
use App\Http\Middleware\ApishadaiSignatureMiddleware;
use App\Http\Middleware\HeadquartersSecurityMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/apishadai')->middleware(['throttle:apishadai-internal', ApishadaiSignatureMiddleware::class])->group(function (): void {
    Route::post('registrations', [InternalController::class, 'requestRegistration']);
    Route::post('registrations/confirm', [InternalController::class, 'confirmRegistration']);
    Route::delete('registrations/{uuid}', [InternalController::class, 'revokeRequest'])->whereUuid('uuid');
    Route::post('changes', [InternalController::class, 'ingest']);
    Route::delete('devices/{uuid}', [InternalController::class, 'revokeClient'])->whereUuid('uuid');
});
Route::prefix('headquarters')->middleware(['throttle:headquarters-access', HeadquartersSecurityMiddleware::class])->group(function (): void {
    Route::post('pos/tareas/{action}', [PosTaskController::class, 'handle'])
        ->whereIn('action', ['listar', 'estado']);
    Route::get('transferencias', [TransferController::class, 'index']);
    Route::get('transferencias/{id}', [TransferController::class, 'show'])->whereNumber('id');
    Route::post('pos/transferencias/{action}', [PosTransferController::class, 'handle'])
        ->whereIn('action', ['enviar', 'recibir', 'anular', 'confirmar-anulacion', 'listar']);
    Route::post('pos/productos/lotes-hijos/{action}', [PosProductLotController::class, 'handle'])
        ->whereIn('action', ['preparar', 'consultar']);
    Route::post('pos/inventario/control/guardar', [InventoryControlController::class, 'handle']);
    Route::post('pos/inventario/matriz/sucursales', [InventoryMatrixController::class, 'branches']);
    Route::post('pos/ventas-perdidas', [InventoryMatrixController::class, 'lostSales']);
    Route::post('pos/inventario/matriz/guardar', [InventoryMatrixController::class, 'handle']);
    Route::post('pos/compras/{action}', [PosPurchaseController::class, 'handle'])
        ->whereIn('action', ['recibir', 'consultar', 'cancelar']);
    Route::post('pos/asistencia/{action}', [PosAttendanceController::class, 'handle'])
        ->whereIn('action', ['registrar', 'listar']);
    Route::get('device', [SyncController::class, 'device']);
    Route::get('sync/cambios', [SyncController::class, 'download']);
    Route::post('sync/confirmar', [SyncController::class, 'acknowledge']);
    Route::post('sync/inicial', [SyncController::class, 'bootstrap']);
    Route::get('sync/inicial/{uuid}/paginas/{page}', [SyncController::class, 'page'])->whereUuid('uuid')->whereNumber('page');
    Route::post('sync/inicial/{uuid}/confirmar', [SyncController::class, 'confirmBootstrap'])->whereUuid('uuid');
});

// Pre-login: each request carries the desktop device's cryptographic proof.
Route::post('desktop/licenses/{action}', [DesktopLicenseController::class, 'handle'])
    ->whereIn('action', ['activate', 'validate'])->middleware('throttle:30,1');

Route::post('desktop/updates/{action}', [DesktopUpdateController::class, 'handle'])
    ->whereIn('action', ['check', 'download', 'apply', 'report'])->middleware('throttle:30,1');
