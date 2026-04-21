<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical upgrade path only: merged 4-digit into 5-digit NGO-style numeric codes.
 * Final structure is dotted hierarchical codes (see 2026_03_23). Do not add new logic here.
 *
 * - Remaps all references from 4-digit account IDs to the corresponding 5-digit account IDs.
 * - Removes 4-digit accounts so only the 5-digit structure remains.
 *
 * Runs on the default (main) database only.
 */
return new class extends Migration
{
    /** 4-digit account code => 5-digit NGO account code (same category) */
    private const CODE_MAP = [
        '1000' => '10000', '1100' => '10100', '1110' => '10100', '1111' => '11001', '1112' => '11001',
        '1120' => '11004', '1121' => '11004', '1200' => '10100', '1210' => '12001', '1220' => '12003',
        '1500' => '10200', '1510' => '14400', '1520' => '14500', '1590' => '14900',
        '2000' => '20000', '2100' => '20100', '2110' => '21001', '2120' => '21006', '2130' => '21003', '2200' => '20100',
        '3000' => '30000', '3100' => '31100', '3200' => '32001', '3300' => '33001',
        '4000' => '40000', '4100' => '41000', '4110' => '41001', '4200' => '42001', '4300' => '43005',
        '5000' => '50000', '5100' => '51001', '5110' => '51002', '5120' => '51001', '5200' => '51001', '5210' => '51001',
        '5300' => '71007', '5310' => '71007', '5320' => '71009', '5330' => '71008',
    ];

    public function up(): void
    {
        $fourDigitCodes = array_keys(self::CODE_MAP);

        foreach ($this->getOrganizationIdsWithFourDigitAccounts($fourDigitCodes) as $orgId) {
            $idMap = $this->buildAccountIdMap($orgId, $fourDigitCodes);
            if (empty($idMap)) {
                continue;
            }

            $this->updateReferences($idMap);
            $this->deleteFourDigitAccounts($orgId, $fourDigitCodes);
        }
    }

    private function getOrganizationIdsWithFourDigitAccounts(array $fourDigitCodes): array
    {
        $placeholders = implode(',', array_fill(0, count($fourDigitCodes), '?'));
        return DB::table('chart_of_accounts')
            ->whereIn('account_code', $fourDigitCodes)
            ->whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id')
            ->all();
    }

    private function buildAccountIdMap(int $orgId, array $fourDigitCodes): array
    {
        $fourDigit = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->whereIn('account_code', $fourDigitCodes)
            ->get()
            ->keyBy('account_code');

        $fiveDigitCodes = array_unique(array_values(self::CODE_MAP));
        $fiveDigit = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->whereIn('account_code', $fiveDigitCodes)
            ->get()
            ->keyBy('account_code');

        $idMap = [];
        foreach (self::CODE_MAP as $fromCode => $toCode) {
            $from = $fourDigit->get($fromCode);
            $to = $fiveDigit->get($toCode);
            if ($from && $to && $from->id !== $to->id) {
                $idMap[(int) $from->id] = (int) $to->id;
            }
        }
        return $idMap;
    }

    private function updateReferences(array $idMap): void
    {
        if (empty($idMap)) {
            return;
        }

        $oldIds = array_keys($idMap);

        foreach ($idMap as $oldId => $newId) {
            DB::table('chart_of_accounts')->where('parent_id', $oldId)->update(['parent_id' => $newId]);
            DB::table('journal_entry_lines')->where('account_id', $oldId)->update(['account_id' => $newId]);
            DB::table('voucher_lines')->where('account_id', $oldId)->update(['account_id' => $newId]);
            DB::table('budget_lines')->where('account_id', $oldId)->update(['account_id' => $newId]);
            DB::table('project_budgets')->where('account_id', $oldId)->update(['account_id' => $newId]);
            DB::table('bank_accounts')->where('gl_account_id', $oldId)->update(['gl_account_id' => $newId]);
            DB::table('cash_accounts')->where('gl_account_id', $oldId)->update(['gl_account_id' => $newId]);
            DB::table('vendors')->where('ap_account_id', $oldId)->update(['ap_account_id' => $newId]);
            if (Schema::hasTable('asset_categories')) {
                DB::table('asset_categories')->where('asset_account_id', $oldId)->update(['asset_account_id' => $newId]);
                DB::table('asset_categories')->where('depreciation_account_id', $oldId)->update(['depreciation_account_id' => $newId]);
                DB::table('asset_categories')->where('accumulated_depreciation_account_id', $oldId)->update(['accumulated_depreciation_account_id' => $newId]);
            }
        }

        if (Schema::hasTable('vendor_invoice_lines')) {
            foreach ($idMap as $oldId => $newId) {
                DB::table('vendor_invoice_lines')->where('account_id', $oldId)->update(['account_id' => $newId]);
            }
        }
    }

    private function deleteFourDigitAccounts(int $orgId, array $fourDigitCodes): void
    {
        $accounts = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->whereIn('account_code', $fourDigitCodes)
            ->orderByDesc('level')
            ->get();

        foreach ($accounts as $row) {
            DB::table('chart_of_accounts')->where('id', $row->id)->delete();
        }
    }

    public function down(): void
    {
        // Cannot restore 4-digit accounts; data has been remapped. No-op.
    }
};
