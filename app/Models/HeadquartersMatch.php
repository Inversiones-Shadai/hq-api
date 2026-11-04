<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeadquartersMatch extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_REVOKED = 'REVOKED';

    protected $table = 'headquarters_matches';

    protected $fillable = [
        'uuid', 'name', 'ip', 'stock_id', 'client_id', 'registration_key_hash', 'approval_code_hash',
        'key_hash', 'headquarters_device_id', 'status', 'attempts', 'expires_at', 'confirmed_at', 'last_used_at', 'source_device_id',
    ];

    protected $hidden = ['registration_key_hash', 'approval_code_hash', 'key_hash', 'ip'];

    protected function casts(): array
    {
        return [
            'stock_id' => 'integer', 'headquarters_device_id' => 'integer', 'attempts' => 'integer',
            'expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'last_used_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(HeadquartersDevice::class, 'headquarters_device_id');
    }
}
