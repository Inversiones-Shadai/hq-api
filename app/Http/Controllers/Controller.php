<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function sendResponse(mixed $data, string $message = 'Operación exitosa.', int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $status, 'data' => $data], $status)->header('Cache-Control', 'no-store');
    }
}
