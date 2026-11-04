<?php

namespace App\Http\Requests\Headquarters;

use App\Http\Requests\BaseRequest;
use App\Services\Headquarters\EntityContract;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IngestRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:100'],
            'changes.*' => ['required', 'array:uuid,entity,entity_id,action,version,schema_version,payload,created_at,stock_ids,removed_stock_ids'],
            'changes.*.uuid' => ['required', 'uuid', 'distinct:strict'],
            'changes.*.entity' => ['required', Rule::in(array_keys(EntityContract::FIELDS))],
            'changes.*.entity_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'changes.*.action' => ['required', Rule::in(['CREATE', 'UPDATE', 'DELETE'])],
            'changes.*.version' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'changes.*.schema_version' => ['required', 'integer', Rule::in([1])],
            'changes.*.payload' => ['present', 'nullable', 'array'],
            'changes.*.created_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'changes.*.stock_ids' => ['sometimes', 'array', 'max:2'],
            'changes.*.stock_ids.*' => ['integer', 'min:0', 'max:2147483647'],
            'changes.*.removed_stock_ids' => ['sometimes', 'array', 'max:2'],
            'changes.*.removed_stock_ids.*' => ['integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('changes', []) as $index => $change) {
                if (! is_array($change) || ! is_string($change['entity'] ?? null) || ! isset(EntityContract::FIELDS[$change['entity']])) {
                    continue;
                }
                $payload = $change['payload'] ?? null;
                $optionalFields = match ($change['entity']) {
                    'usuariospos' => ['vendedor'],
                    'lotes' => ['valoracion'],
                    default => [],
                };
                $invalid = ($change['action'] ?? '') === 'DELETE' ? $payload !== null
                    : ! is_array($payload) || (string) ($payload['id'] ?? '') !== (string) ($change['entity_id'] ?? '')
                    || array_diff(array_keys($payload), EntityContract::FIELDS[$change['entity']]) !== []
                    || array_diff(EntityContract::FIELDS[$change['entity']], array_keys($payload), $optionalFields) !== [];
                if ($invalid) {
                    $validator->errors()->add("changes.$index.payload", 'Payload incompatible con el contrato de sincronización.');
                }
                if ($change['entity'] === 'lotes' && ($change['action'] ?? '') !== 'DELETE'
                    && is_array($payload) && ($payload['valoracion'] ?? null) !== null) {
                    $valuation = $payload['valoracion'];
                    $fields = ['tasa_isv', 'tratamiento'];
                    $valid = is_array($valuation) && array_diff(array_keys($valuation), $fields) === []
                        && array_diff($fields, array_keys($valuation)) === [];
                    if ($valid) {
                        $rate = $valuation['tasa_isv'];
                        $treatment = $valuation['tratamiento'];
                        $valid = ($rate === null || (! is_bool($rate) && is_numeric($rate) && $rate >= 0 && $rate <= 100))
                            && ($treatment === null || in_array($treatment, ['GRAVADO', 'EXENTO', 'EXONERADO'], true))
                            && ($rate === null || ($rate > 0 ? $treatment === 'GRAVADO' : in_array($treatment, ['EXENTO', 'EXONERADO'], true)));
                    }
                    if (! $valid) {
                        $validator->errors()->add("changes.$index.payload.valoracion", 'ISV de valoración inválido.');
                    }
                }
                if ($change['entity'] === 'usuariospos' && ($change['action'] ?? '') !== 'DELETE' && is_array($payload)) {
                    $seller = $payload['vendedor'] ?? null;
                    if ($seller !== null && (! is_string($seller) || trim($seller) === '' || mb_strlen($seller) > 200)) {
                        $validator->errors()->add("changes.$index.payload.vendedor", 'Vendedor POS inválido.');
                    }
                    $credentials = $payload['credenciales'] ?? null;
                    $fields = ['version', 'purpose', 'algorithm', 'usuario', 'salt', 'hash', 'iterations'];
                    $valid = is_array($credentials) && array_diff(array_keys($credentials), $fields) === [] && array_diff($fields, array_keys($credentials)) === []
                        && ($credentials['version'] ?? null) === 2 && ($credentials['purpose'] ?? null) === 'ShPOS' && ($credentials['algorithm'] ?? null) === 'PBKDF2-SHA512'
                        && is_int($credentials['iterations'] ?? null) && $credentials['iterations'] >= 210000 && $credentials['iterations'] <= 1000000;
                    foreach (['usuario' => null, 'salt' => 32, 'hash' => 64] as $field => $length) {
                        $encoded = $credentials[$field] ?? null;
                        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
                        $valid = $valid && $decoded !== false && base64_encode($decoded) === $encoded
                            && ($length !== null ? strlen($decoded) === $length : preg_match('/\A[a-zA-Z0-9._@-]{1,200}\z/', $decoded));
                    }
                    $name = is_string($payload['nombre'] ?? null) ? base64_decode($payload['nombre'], true) : false;
                    $valid = $valid && $name !== false && base64_encode($name) === $payload['nombre'] && mb_check_encoding($name, 'UTF-8') && trim($name) !== '' && mb_strlen($name) <= 200
                        && is_bool($payload['activo'] ?? null) && ! is_bool($payload['rol_id'] ?? null) && filter_var($payload['rol_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
                    if (! $valid) {
                        $validator->errors()->add("changes.$index.payload", 'Credenciales locales POS en Base64 inválidas.');
                    }
                }
                if (in_array($change['entity'], ['modulospos', 'vistaspos', 'botonespos', 'permisospos'], true)
                    && ($change['action'] ?? '') !== 'DELETE' && is_array($payload)) {
                    $valid = is_bool($payload['activo'] ?? null);
                    foreach (EntityContract::FIELDS[$change['entity']] as $field) {
                        if (str_ends_with($field, '_id')) {
                            $valid = $valid && ! is_bool($payload[$field] ?? null) && filter_var($payload[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
                        }
                    }
                    if ($change['entity'] !== 'permisospos') {
                        $valid = $valid && is_string($payload['codigo'] ?? null) && preg_match('/\A[a-zA-Z0-9._-]{1,80}\z/', $payload['codigo'])
                            && is_string($payload['nombre'] ?? null) && trim($payload['nombre']) !== '' && mb_strlen($payload['nombre']) <= 100;
                    }
                    if (! $valid) {
                        $validator->errors()->add("changes.$index.payload", 'Jerarquía de permisos POS inválida.');
                    }
                }
                if ($change['entity'] === 'rolespos' && ($change['action'] ?? '') !== 'DELETE' && is_array($payload)) {
                    if (! is_bool($payload['activo'] ?? null) || ! is_string($payload['nombre'] ?? null) || trim($payload['nombre']) === '' || mb_strlen($payload['nombre']) > 80
                        || is_bool($payload['nivel'] ?? null) || filter_var($payload['nivel'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]) === false) {
                        $validator->errors()->add("changes.$index.payload", 'Nivel de rol POS inválido.');
                    }
                }
                if ($change['entity'] === 'permisosusuariospos' && ($change['action'] ?? '') !== 'DELETE' && is_array($payload)) {
                    $valid = true;
                    foreach (['usuario', 'modulo', 'vista', 'actionvista'] as $field) {
                        $valid = $valid && ! is_bool($payload[$field] ?? null) && filter_var($payload[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
                    }
                    foreach (['lifetime', 'tipo_tiempo'] as $field) {
                        $valid = $valid && (array_key_exists($field, $payload) && ($payload[$field] === null || (! is_bool($payload[$field]) && filter_var($payload[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false)));
                    }
                    if (! $valid) {
                        $validator->errors()->add("changes.$index.payload", 'Permiso directo POS inválido.');
                    }
                }
                $stocks = $change['stock_ids'] ?? [];
                $removed = $change['removed_stock_ids'] ?? [];
                if (! is_array($stocks) || ! is_array($removed) || count(array_filter([...$stocks, ...$removed], 'is_scalar')) !== count($stocks) + count($removed)) {
                    continue;
                }
                if (count(array_unique($stocks, SORT_REGULAR)) !== count($stocks) || count(array_unique($removed, SORT_REGULAR)) !== count($removed)) {
                    $validator->errors()->add("changes.$index.stock_ids", 'El alcance no permite stocks repetidos en una transferencia.');
                }
                $globalScope = array_map('intval', $stocks) === [EntityContract::GLOBAL_DISCOUNT_SCOPE];
                $globalDiscount = $change['entity'] === 'catalogospos' && ($change['action'] ?? '') !== 'DELETE'
                    && is_array($payload) && ($payload['tipo'] ?? '') === 'DESCUENTO'
                    && array_key_exists('stock_id', $payload) && $payload['stock_id'] === null;
                if (in_array(0, array_map('intval', $stocks), true)
                    && (! $globalScope || $change['entity'] !== 'catalogospos'
                        || (($change['action'] ?? '') !== 'DELETE' && ! $globalDiscount))) {
                    $validator->errors()->add("changes.$index.stock_ids", 'El alcance global solo admite reglas de descuentos.');
                }
                if (in_array($change['entity'], EntityContract::SCOPED, true)) {
                    if ($stocks === [] || array_intersect($stocks, $removed) !== []) {
                        $validator->errors()->add("changes.$index.stock_ids", 'La transferencia requiere un alcance de stock válido.');
                    }
                    if (($change['action'] ?? '') !== 'DELETE' && is_array($payload)) {
                        $expected = $change['entity'] === 'transferencias'
                            ? array_values(array_unique([(int) ($payload['stock_de'] ?? 0), (int) ($payload['stock_para'] ?? 0)]))
                            : ($globalDiscount ? [EntityContract::GLOBAL_DISCOUNT_SCOPE] : [(int) ($payload['stock_id'] ?? 0)]);
                        sort($expected);
                        $actual = array_map('intval', $stocks);
                        sort($actual);
                        if ($expected !== $actual) {
                            $validator->errors()->add("changes.$index.stock_ids", 'El alcance debe coincidir con origen y destino.');
                        }
                    }
                } elseif ($stocks !== [] || $removed !== []) {
                    $validator->errors()->add("changes.$index.stock_ids", 'Esta entidad no permite un alcance por stock.');
                }
                if ($change['entity'] === 'devices' && ($change['action'] ?? '') !== 'DELETE' && is_array($payload)
                    && (! is_string($payload['hq_client_uuid'] ?? null) || ! Str::isUuid($payload['hq_client_uuid']) || ! is_bool($payload['active'] ?? null)
                        || filter_var($payload['stock_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false)) {
                    $validator->errors()->add("changes.$index.payload", 'Vínculo de dispositivo inválido.');
                }
            }
        }];
    }
}
