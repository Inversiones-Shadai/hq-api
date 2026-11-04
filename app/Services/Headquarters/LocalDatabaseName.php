<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;

class LocalDatabaseName
{
    public static function forStock(int $stockId): string
    {
        if ($stockId < 1) {
            throw new HeadquartersException('El registro requiere un stock autorizado.', 409);
        }

        return 'ShHQ_Stock'.$stockId;
    }
}
