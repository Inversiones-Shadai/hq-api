<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DesktopLicenseClient
{
    public function send(string $action, array $data): array
    {
        return $this->exchange('licenses', $action, $data);
    }

    public function sendUpdate(string $action, array $data): array
    {
        return $this->exchange('updates', $action, $data);
    }

    private function exchange(string $resource, string $action, array $data): array
    {
        $base = rtrim((string) config('headquarters.apishadai_url'), '/');
        $secret = (string) config('headquarters.apishadai_secret');
        $local = in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true);
        $actions = $resource === 'updates' ? ['check', 'download', 'apply', 'report'] : ['activate', 'validate'];
        if (strlen($secret) < 64 || ! $base || ! in_array($action, $actions, true)
            || (parse_url($base, PHP_URL_SCHEME) !== 'https' && ! ($local && app()->environment(['local', 'testing'])))) {
            throw new HeadquartersException('Configura el canal seguro de licencias con la API central.', 503);
        }
        $url = $base.'/headquarters/'.$resource.'/'.$action;
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $canonical = "POST\n".parse_url($url, PHP_URL_PATH)."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
        try {
            $response = Http::acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout($resource === 'updates' ? 20 : 12)->withHeaders([
                'X-HQ-Key-Id' => config('headquarters.apishadai_key_id'), 'X-HQ-Timestamp' => $timestamp,
                'X-HQ-Nonce' => $nonce, 'X-HQ-Signature' => hash_hmac('sha256', $canonical, $secret),
            ])->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException) {
            throw new HeadquartersException('No se pudo consultar la autorización con la API central.', 503);
        }
        if (! $response->successful()) {
            throw new HeadquartersException('No se pudo consultar la autorización. Revisa la conexión y la fecha y hora del equipo.', $response->status() >= 500 || $response->status() < 400 ? 503 : $response->status());
        }
        if ($resource === 'updates' && strlen($response->body()) > 131072) {
            throw new HeadquartersException('La respuesta de actualizaciones excede el límite permitido.', 503);
        }
        $result = $response->json('data');
        if (! is_array($result) || ! is_string($result['payload'] ?? null) || ! is_string($result['signature'] ?? null)) {
            throw new HeadquartersException('La API central devolvió una autorización inválida.', 503);
        }

        return $result;
    }
}
