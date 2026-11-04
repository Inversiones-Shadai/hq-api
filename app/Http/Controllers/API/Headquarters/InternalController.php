<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Headquarters\ConfirmRegistrationRequest;
use App\Http\Requests\Headquarters\IngestRequest;
use App\Http\Requests\Headquarters\RegistrationRequest;
use App\Http\Resources\Headquarters\HeadquartersDeviceResource;
use App\Services\Headquarters\IngestService;
use App\Services\Headquarters\RegistrationService;
use Illuminate\Http\JsonResponse;

class InternalController extends Controller
{
    public function requestRegistration(RegistrationRequest $request, RegistrationService $service): JsonResponse
    {
        return $this->sendResponse($service->requestRegistration($request->validated()), 'Solicitud creada.', 202);
    }

    public function confirmRegistration(ConfirmRegistrationRequest $request, RegistrationService $service): JsonResponse
    {
        $result = $service->confirmRegistration($request->validated());
        $result['device'] = HeadquartersDeviceResource::make($result['device'])->resolve($request);

        return $this->sendResponse($result, 'Cliente autorizado.', 201);
    }

    public function revokeRequest(string $uuid, RegistrationService $service): JsonResponse
    {
        $service->revokeRequest($uuid);

        return $this->sendResponse(null, 'Solicitud revocada.');
    }

    public function ingest(IngestRequest $request, IngestService $service): JsonResponse
    {
        return $this->sendResponse($service->ingest($request->validated()['changes']));
    }

    public function revokeClient(string $uuid, RegistrationService $service): JsonResponse
    {
        $service->revokeClient($uuid);

        return $this->sendResponse(null, 'Cliente revocado.');
    }
}
