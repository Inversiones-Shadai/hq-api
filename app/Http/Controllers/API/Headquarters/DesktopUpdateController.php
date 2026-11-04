<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Http\Controllers\Controller;
use App\Services\Headquarters\DesktopLicenseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopUpdateController extends Controller
{
    public function handle(Request $request, string $action, DesktopLicenseClient $client): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 24000, 413);
        abort_if(app()->isProduction() && ! $request->isSecure(), 403);
        abort_if(array_diff(array_keys($request->all()), ['payload', 'signature']) !== [], 422, 'Solo se admite la prueba firmada del equipo.');
        $envelope = $request->validate(['payload' => ['required', 'string', 'max:16000'], 'signature' => ['required', 'string', 'max:1500']]);

        return $this->sendResponse($client->sendUpdate($action, $envelope))->header('Cache-Control', 'no-store, private');
    }
}
