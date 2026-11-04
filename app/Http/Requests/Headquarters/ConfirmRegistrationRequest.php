<?php

namespace App\Http\Requests\Headquarters;

use App\Http\Requests\BaseRequest;

class ConfirmRegistrationRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'registration_id' => ['required', 'uuid'], 'ip' => ['required', 'ip'],
            'registration_key' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'client_id' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ];
    }
}
