<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class SyncChange extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTION_CREATE = 'CREATE';

    public const ACTION_UPDATE = 'UPDATE';

    public const ACTION_DELETE = 'DELETE';

    public const ACTIONS = [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE];

    protected $table = 'sync_changes';

    protected $fillable = [
        'uuid', 'entity', 'entity_id', 'action', 'version', 'schema_version', 'payload', 'created_at',
    ];

    protected $attributes = ['schema_version' => 1];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'entity_id' => 'integer',
            'version' => 'integer',
            'schema_version' => 'integer',
            'payload' => 'array',
            'stock_ids' => 'array', 'removed_stock_ids' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $change): void {
            if (! in_array($change->action, self::ACTIONS, true)) {
                throw new InvalidArgumentException('Invalid synchronization action.');
            }

            foreach (['version', 'schema_version'] as $field) {
                $value = $change->getAttributes()[$field] ?? null;
                if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new InvalidArgumentException("{$field} must be a positive integer.");
                }
            }

            $change->uuid ??= (string) Str::uuid();
        });
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Published synchronization changes cannot be updated.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Published synchronization changes can only be deleted by the retention process.');
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): mixed
    {
        throw new LogicException('Published synchronization changes cannot be updated.');
    }
}
