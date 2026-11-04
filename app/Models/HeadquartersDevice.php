<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HeadquartersDevice extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'headquarters_devices';

    protected $fillable = ['uuid', 'client_id', 'name', 'ip', 'stock_id', 'status', 'registered_at', 'last_seen_at', 'source_device_id'];

    protected $hidden = ['ip'];

    protected function casts(): array
    {
        return ['stock_id' => 'integer', 'registered_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(HeadquartersMatch::class, 'headquarters_device_id');
    }

    public function consumer(): HasOne
    {
        return $this->hasOne(SyncConsumer::class, 'headquarters_device_id');
    }
}
