<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical: remapped NGO all-numeric account_code values to a unified L1 digit order before dotted migration.
 * Superseded by dotted codes (2026_03_23). Kept for databases that still had legacy numeric CoA at this step.
 *
 * Income/Revenue 10000, Expenses 20000, Assets 30000, Liabilities 40000, Fund Balance 50000.
 * Mapping for numeric codes: first digit 1→3, 2→4, 3→5, 4→1, 5→2 (suffix unchanged).
 * Legacy expense 7xxxx/8xxxx (e.g. 71007): first digit → 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $rows = DB::table('chart_of_accounts')->select('id', 'organization_id', 'account_code', 'account_type')->get();

        $original = [];
        $final = [];
        foreach ($rows as $row) {
            $orig = (string) $row->account_code;
            $original[$row->id] = $orig;
            $final[$row->id] = $this->mapAccountCode($orig, (string) $row->account_type);
        }

        // Collision check per organization
        $byOrg = [];
        foreach ($rows as $row) {
            $orgId = (int) ($row->organization_id ?? 0);
            $code = $final[$row->id];
            $byOrg[$orgId][$code][] = $row->id;
        }
        foreach ($byOrg as $orgId => $codes) {
            foreach ($codes as $code => $ids) {
                if (count($ids) > 1) {
                    throw new \RuntimeException(
                        "COA remap collision: org {$orgId} code {$code} for ids ".implode(',', $ids)
                    );
                }
            }
        }

        DB::transaction(function () use ($rows, $final) {
            foreach ($rows as $row) {
                $temp = 'T'.str_pad((string) $row->id, 12, '0', STR_PAD_LEFT);
                DB::table('chart_of_accounts')->where('id', $row->id)->update(['account_code' => $temp]);
            }
            foreach ($rows as $row) {
                DB::table('chart_of_accounts')->where('id', $row->id)->update(['account_code' => $final[$row->id]]);
            }
        });

        $orgIds = DB::table('chart_of_accounts')->distinct()->pluck('organization_id');
        foreach ($orgIds as $orgId) {
            if ($orgId === null) {
                continue;
            }
            foreach ([0, 1] as $officeId) {
                \Illuminate\Support\Facades\Cache::forget("coa_tree_{$orgId}_o{$officeId}_t0");
                \Illuminate\Support\Facades\Cache::forget("coa_tree_{$orgId}_o{$officeId}_t1");
            }
        }
    }

    private function mapAccountCode(string $code, string $accountType): string
    {
        $code = trim($code);
        if ($code === '' || ! ctype_digit($code)) {
            return $code;
        }

        if ($accountType === 'expense' && ($code[0] === '7' || $code[0] === '8') && strlen($code) >= 5) {
            return '2'.substr($code, 1);
        }

        $first = $code[0];
        $map = [
            '1' => '3',
            '2' => '4',
            '3' => '5',
            '4' => '1',
            '5' => '2',
        ];
        if (isset($map[$first])) {
            return $map[$first].substr($code, 1);
        }

        return $code;
    }

    public function down(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $inverseFirst = [
            '3' => '1',
            '4' => '2',
            '5' => '3',
            '1' => '4',
            '2' => '5',
        ];

        $rows = DB::table('chart_of_accounts')->select('id', 'organization_id', 'account_code', 'account_type')->get();

        $final = [];
        foreach ($rows as $row) {
            $code = (string) $row->account_code;
            $final[$row->id] = $this->reverseMapAccountCode($code, (string) $row->account_type, $inverseFirst);
        }

        $byOrg = [];
        foreach ($rows as $row) {
            $orgId = (int) ($row->organization_id ?? 0);
            $byOrg[$orgId][$final[$row->id]][] = $row->id;
        }
        foreach ($byOrg as $orgId => $codes) {
            foreach ($codes as $code => $ids) {
                if (count($ids) > 1) {
                    throw new \RuntimeException(
                        "COA remap rollback collision: org {$orgId} code {$code}"
                    );
                }
            }
        }

        DB::transaction(function () use ($rows, $final) {
            foreach ($rows as $row) {
                $temp = 'R'.str_pad((string) $row->id, 12, '0', STR_PAD_LEFT);
                DB::table('chart_of_accounts')->where('id', $row->id)->update(['account_code' => $temp]);
            }
            foreach ($rows as $row) {
                DB::table('chart_of_accounts')->where('id', $row->id)->update(['account_code' => $final[$row->id]]);
            }
        });
    }

    private function reverseMapAccountCode(string $code, string $accountType, array $inverseFirst): string
    {
        if (! ctype_digit($code)) {
            return $code;
        }
        // Reverse 71007 → 21007
        if ($accountType === 'expense' && $code[0] === '2' && strlen($code) === 5 && str_starts_with($code, '21')) {
            return '7'.substr($code, 1);
        }
        $first = $code[0];
        if (isset($inverseFirst[$first])) {
            return $inverseFirst[$first].substr($code, 1);
        }

        return $code;
    }
};
