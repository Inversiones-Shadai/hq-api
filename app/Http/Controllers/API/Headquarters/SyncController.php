<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Headquarters\SyncAcknowledgeRequest;
use App\Http\Requests\Headquarters\SyncDownloadRequest;
use App\Http\Resources\Headquarters\HeadquartersDeviceResource;
use App\Services\Headquarters\BootstrapService;
use App\Services\Headquarters\SyncDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function device(Request $request): JsonResponse
    {
        return $this->sendResponse(HeadquartersDeviceResource::make($request->attributes->get('headquarters_device')));
    }

    public function download(SyncDownloadRequest $request, SyncDeliveryService $service): JsonResponse
    {
        return $this->sendResponse($service->download($request->attributes->get('headquarters_consumer'), (int) ($request->validated()['limit'] ?? 100)));
    }

    public function acknowledge(SyncAcknowledgeRequest $request, SyncDeliveryService $service): JsonResponse
    {
        return $this->sendResponse($service->acknowledge($request->attributes->get('headquarters_consumer'), $request->validated()['batch_id']));
    }

    public function bootstrap(Request $request, BootstrapService $service): JsonResponse
    {
        return $this->sendResponse($service->create($request->attributes->get('headquarters_consumer')), 'Sincronización inicial creada.', 201);
    }

    public function page(Request $request, BootstrapService $service, string $uuid, int $page): JsonResponse
    {
        return $this->sendResponse($service->page($request->attributes->get('headquarters_consumer'), $uuid, $page));
    }

    public function confirmBootstrap(Request $request, BootstrapService $service, string $uuid): JsonResponse
    {
        return $this->sendResponse($service->acknowledge($request->attributes->get('headquarters_consumer'), $uuid));
    }
}
