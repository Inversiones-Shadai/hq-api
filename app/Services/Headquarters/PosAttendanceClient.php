<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PosAttendanceClient
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function send(string $action, array $data): array
    {
        $base = rtrim((string) config('headquarters.apishadai_url'), '/');
        $secret = (string) config('headquarters.apishadai_secret');
        $local = in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true);
        if (strlen($secret) < 64 || ! $base || ! in_array($action, ['registrar', 'listar'], true)
            || (parse_url($base, PHP_URL_SCHEME) !== 'https' && ! ($local && app()->environment(['local', 'testing'])))) {
            throw new HeadquartersException('Configura el canal seguro de asistencia con la API central.', 503);
        }
        $url = $base.'/headquarters/pos/asistencia/'.$action;
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $canonical = "POST\n".parse_url($url, PHP_URL_PATH)."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
        try {
            $response = Http::acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(15)->withHeaders([
                'X-HQ-Key-Id' => config('headquarters.apishadai_key_id'), 'X-HQ-Timestamp' => $timestamp,
                'X-HQ-Nonce' => $nonce, 'X-HQ-Signature' => hash_hmac('sha256', $canonical, $secret),
            ])->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException $exception) {
            throw new HeadquartersException('No se pudo comunicar el registro de asistencia con la API central. Conserva la solicitud original.', 503);
        }
        if (! $response->successful()) {
            throw new HeadquartersException('La API central no confirmó el registro de asistencia. Conserva la identidad y el contenido originales.', $response->status() >= 500 ? 503 : $response->status());
        }
        $result = $response->json('data');
        if (! is_array($result)) {
            throw new HeadquartersException('La API central devolvió una confirmación inválida.', 503);
        }

        return $result;
    }
}
