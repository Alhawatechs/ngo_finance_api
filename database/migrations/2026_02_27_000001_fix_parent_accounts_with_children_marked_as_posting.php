<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fix accounts that have children but are incorrectly marked as is_posting=true.
 * In the Chart of Accounts, only header accounts can have children; posting accounts are leaf nodes.
 * This ensures parents with children are is_header=true, is_posting=false.
 */
return new class extends Migration
{
    public function up(): void
    {
        $updated = DB::table('chart_of_accounts as p')
            ->join('chart_of_accounts as c', 'c.parent_id', '=', 'p.id')
            ->where('p.is_posting', true)
            ->where(function ($q) {
                $q->where('p.is_header', false)->orWhereNull('p.is_header');
            })
            ->distinct()
            ->pluck('p.id')
            ->unique()
            ->values();

        if ($updated->isNotEmpty()) {
            DB::table('chart_of_accounts')
                ->whereIn('id', $updated)
                ->update(['is_header' => true, 'is_posting' => false]);

            // Clear COA tree cache so next fetch gets corrected data
            $orgIds = DB::table('chart_of_accounts')->distinct()->pluck('organization_id');
            foreach ($orgIds as $orgId) {
                Cache::forget("coa_tree_{$orgId}_o0_t0");
                Cache::forget("coa_tree_{$orgId}_o0_t1");
            }
        }
    }

    public function down(): void
    {
        // Cannot safely revert: we don't know which accounts were originally posting
    }
};
