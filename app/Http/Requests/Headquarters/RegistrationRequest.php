<?php

namespace App\Http\Requests\Headquarters;

use App\Http\Requests\BaseRequest;

class RegistrationRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/\A[^|\x00-\x1F\x7F]+\z/u'],
            'ip' => ['required', 'ip'], 'stock_id' => ['required', 'integer', 'min:1'],
            'source_device_id' => ['required', 'integer', 'min:1'],
            'hq_client_uuid' => ['required', 'uuid'],
        ];
    }
}
