<?php

namespace App\Support;

use App\Models\Office;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidates server-side chart-of-accounts tree cache for every office context.
 * Keys match {@see \App\Http\Controllers\Api\V1\Finance\ChartOfAccountController::tree}.
 *
 * When the default cache driver is "database", {@see ChartOfAccountController::tree} reads/writes
 * via {@see Cache::store('file')} to avoid large payloads in SQL; mutations must forget the same store.
 */
final class ChartOfAccountsCache
{
    /**
     * Forget tree cache keys for all offices (and global o0) for this organization.
     */
    public static function forgetForOrganization(int $organizationId): void
    {
        $officeIds = Office::query()
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $officeIds = array_values(array_unique(array_merge([0], $officeIds)));

        foreach ($officeIds as $officeId) {
            foreach (['0', '1'] as $t) {
                $key = "coa_tree_{$organizationId}_o{$officeId}_t{$t}";
                Cache::forget($key);
                if (config('cache.default') === 'database') {
                    Cache::store('file')->forget($key);
                }
            }
        }
    }
}
