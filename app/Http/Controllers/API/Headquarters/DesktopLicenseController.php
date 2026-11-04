<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Http\Controllers\Controller;
use App\Services\Headquarters\DesktopLicenseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopLicenseController extends Controller
{
    public function handle(Request $request, string $action, DesktopLicenseClient $client): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 24000, 413);
        abort_if(app()->isProduction() && ! $request->isSecure(), 403);
        $envelope = $request->validate([
            'payload' => ['required', 'string', 'max:16000'],
            'signature' => ['required', 'string', 'max:1500'],
        ]);

        return $this->sendResponse($client->send($action, $envelope));
    }
}
