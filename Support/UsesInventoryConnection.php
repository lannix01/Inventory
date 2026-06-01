<?php

namespace App\Modules\Inventory\Support;

trait UsesInventoryConnection
{
    public function getConnectionName()
    {
        return InventoryDatabase::connectionName();
    }
}
