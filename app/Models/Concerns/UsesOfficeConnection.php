<?php

namespace App\Models\Concerns;

use App\Services\OfficeContext;

/**
 * Use this trait on models that live in the office-specific financial database.
 * When OfficeContext has an office with a provisioned DB, queries use that connection;
 * otherwise the default (central) connection is used.
 */
trait UsesOfficeConnection
{
    public function getConnectionName(): ?string
    {
        return OfficeContext::connection();
    }
}
