<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Remove the Subsidiary (L4) layer from Chart of Accounts.
 * Flattens 5-level to 4-level: Category → Subcategory → General Ledger → Account.
 * All posting accounts link directly to General Ledger (L3) accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orgIds = ChartOfAccount::query()->distinct()->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            $this->flattenForOrganization($orgId);
        }

        foreach ($orgIds as $orgId) {
            Cache::forget("coa_tree_{$orgId}_o0_t0");
            Cache::forget("coa_tree_{$orgId}_o0_t1");
        }
    }

    private function flattenForOrganization(int $orgId): void
    {
        // Step 1: L5 accounts -> reparent to L3 (grandparent), set level=4
        $l5Accounts = ChartOfAccount::withTrashed()
            ->where('organization_id', $orgId)
            ->where('level', 5)
            ->get();

        foreach ($l5Accounts as $acc) {
            $parent = ChartOfAccount::find($acc->parent_id);
            if (!$parent || $parent->level != 4) {
                continue;
            }
            $grandparent = ChartOfAccount::find($parent->parent_id);
            if (!$grandparent) {
                continue;
            }
            $acc->update([
                'parent_id' => $grandparent->id,
                'level' => 4,
            ]);
        }

        // Step 2: L4 posting accounts (no children) -> reparent to L3, set level=4
        $l4Leaf = ChartOfAccount::withTrashed()
            ->where('organization_id', $orgId)
            ->where('level', 4)
            ->whereDoesntHave('children')
            ->get();

        foreach ($l4Leaf as $acc) {
            $parent = ChartOfAccount::find($acc->parent_id);
            if (!$parent) {
                continue;
            }
            // If parent is L4, reparent to L3 (grandparent)
            if ($parent->level == 4) {
                $grandparent = ChartOfAccount::find($parent->parent_id);
                if ($grandparent) {
                    $acc->update([
                        'parent_id' => $grandparent->id,
                        'level' => 4,
                    ]);
                }
            }
        }

        // Step 3: Delete L4 header accounts (now empty; their children were moved)
        ChartOfAccount::withTrashed()
            ->where('organization_id', $orgId)
            ->where('level', 4)
            ->where('is_header', true)
            ->forceDelete();
    }

    public function down(): void
    {
        // Reversing would require restoring L4 structure from L4 accounts - not trivial.
        // Migration is one-way. Restore from backup if rollback needed.
    }
};
