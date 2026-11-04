<?php

namespace App\Http\Requests\Headquarters;

use App\Http\Requests\BaseRequest;

class SyncAcknowledgeRequest extends BaseRequest
{
    public function rules(): array
    {
        return ['batch_id' => ['required', 'uuid']];
    }
}
