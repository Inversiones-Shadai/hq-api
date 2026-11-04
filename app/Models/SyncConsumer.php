<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SyncConsumer extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'sync_consumers';

    protected $fillable = [
        'uuid', 'name', 'stock_id', 'headquarters_device_id', 'last_sync_sequence', 'last_seen_at', 'status', 'scope_revision', 'needs_bootstrap',
    ];

    protected $attributes = [
        'last_sync_sequence' => 0,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'scope_revision' => 'integer', 'needs_bootstrap' => 'boolean',

            'stock_id' => 'integer',
            'headquarters_device_id' => 'integer',
            'last_sync_sequence' => 'integer',
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $consumer): void {
            $consumer->uuid ??= (string) Str::uuid();
        });
    }

    public function headquartersDevice(): BelongsTo
    {
        return $this->belongsTo(HeadquartersDevice::class, 'headquarters_device_id');
    }
}
