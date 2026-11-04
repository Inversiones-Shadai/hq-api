<?php

namespace App\Http\Requests\Headquarters;

use App\Http\Requests\BaseRequest;

class SyncDownloadRequest extends BaseRequest
{
    public function rules(): array
    {
        return ['limit' => ['sometimes', 'integer', 'min:1', 'max:500']];
    }
}
