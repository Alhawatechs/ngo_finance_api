<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assigns dotted account codes from legacy all-numeric 5+ digit codes using tree shape (parent_id).
 * Idempotent: safe to run multiple times per organization.
 */
final class CoaDottedMigrationService
{
    /**
     * @param  Collection<int, object{ id: int, parent_id: ?int, account_code: string, account_type: string}>  $rows
     * @return array<int, string> id => new account_code
     */
    public function buildIdToNewCode(Collection $rows): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r->id] = $r;
        }

        $children = [];
        foreach ($rows as $r) {
            $pid = $r->parent_id !== null ? (int) $r->parent_id : null;
            if ($pid === null) {
                if (! isset($children['_root'])) {
                    $children['_root'] = [];
                }
                $children['_root'][] = (int) $r->id;
            } else {
                $children[$pid][] = (int) $r->id;
            }
        }

        $sortLegacy = function (int $a, int $b) use ($byId): int {
            $ca = (string) $byId[$a]->account_code;
            $cb = (string) $byId[$b]->account_code;
            if (ctype_digit($ca) && ctype_digit($cb)) {
                $ia = (int) $ca;
                $ib = (int) $cb;
                if ($ia !== $ib) {
                    return $ia <=> $ib;
                }
            }

            return strcmp($ca, $cb);
        };

        foreach ($children as &$list) {
            if (is_array($list)) {
                usort($list, $sortLegacy);
            }
        }
        unset($list);

        $typeToDigit = [
            'revenue' => '1',
            'expense' => '2',
            'asset' => '3',
            'liability' => '4',
            'equity' => '5',
        ];

        $roots = $children['_root'] ?? [];
        usort($roots, $sortLegacy);

        $out = [];
        $seenTypes = [];

        foreach ($roots as $rid) {
            $type = (string) $byId[$rid]->account_type;
            if (! isset($typeToDigit[$type])) {
                throw new \RuntimeException("COA migrate: root id {$rid} has unknown account_type {$type}");
            }
            if (isset($seenTypes[$type])) {
                throw new \RuntimeException("COA migrate: duplicate root account_type {$type} for ids {$seenTypes[$type]} and {$rid}");
            }
            $seenTypes[$type] = $rid;
            $out[$rid] = $typeToDigit[$type];
        }

        $walk = function (int $parentId, string $parentCode, int $parentDepth) use (&$walk, &$out, $children): void {
            $cidList = $children[$parentId] ?? [];
            $i = 0;
            foreach ($cidList as $cid) {
                $i++;
                if ($parentDepth === 1) {
                    $newCode = $parentCode.(string) $i;
                } else {
                    $newCode = $parentCode.'.'.(string) $i;
                }
                $out[$cid] = $newCode;
                $walk($cid, $newCode, $parentDepth + 1);
            }
        };

        foreach ($roots as $rid) {
            $walk($rid, $out[$rid], 1);
        }

        return $out;
    }

    /**
     * Ensure one organization's chart uses dotted codes (not legacy 5+ digit NGO numeric) and account_code_sort is set.
     * Legacy detection: any all-numeric code with length ≥ 5. Skips remap if any dotted code exists in the same org (mixed state must be fixed manually).
     */
    public function ensureOrganizationDottedAndSort(int $orgId): void
    {
        $rows = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->select('id', 'organization_id', 'parent_id', 'account_code', 'account_type')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $hasLegacyNumeric = $rows->contains(function ($r) {
            $c = trim((string) $r->account_code);

            return $c !== '' && ctype_digit($c) && strlen($c) >= 5;
        });

        $hasDotted = $rows->contains(function ($r) {
            return str_contains(trim((string) $r->account_code), '.');
        });

        if ($hasLegacyNumeric && $hasDotted) {
            Log::warning('COA dotted migration: organization has mixed dotted and legacy 5+ digit numeric codes; skipping automatic remap. Fix data manually.', [
                'organization_id' => $orgId,
            ]);
            foreach ($rows as $row) {
                $c = trim((string) $row->account_code);
                $sort = AccountCodeScheme::sortKey($c);
                DB::table('chart_of_accounts')->where('id', $row->id)->update(['account_code_sort' => $sort]);
            }
            foreach ([0, 1] as $officeId) {
                Cache::forget("coa_tree_{$orgId}_o{$officeId}_t0");
                Cache::forget("coa_tree_{$orgId}_o{$officeId}_t1");
            }

            return;
        }

        if ($hasLegacyNumeric) {
            $map = $this->buildIdToNewCode($rows);

            $final = [];
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $final[$id] = $map[$id] ?? (string) $row->account_code;
            }

            $byOrg = [];
            foreach ($rows as $row) {
                $oid = (int) $row->organization_id;
                $code = $final[(int) $row->id];
                $byOrg[$oid][$code][] = (int) $row->id;
            }
            foreach ($byOrg as $oid => $codes) {
                foreach ($codes as $code => $ids) {
                    if (count($ids) > 1) {
                        throw new \RuntimeException('COA dotted remap collision: org '.$oid.' code '.$code.' ids '.implode(',', $ids));
                    }
                }
            }

            DB::transaction(function () use ($rows, $final) {
                foreach ($rows as $row) {
                    $temp = 'T'.str_pad((string) $row->id, 12, '0', STR_PAD_LEFT);
                    DB::table('chart_of_accounts')->where('id', $row->id)->update(['account_code' => $temp]);
                }
                foreach ($rows as $row) {
                    $id = (int) $row->id;
                    $newCode = $final[$id];
                    $sort = AccountCodeScheme::sortKey($newCode);
                    DB::table('chart_of_accounts')->where('id', $row->id)->update([
                        'account_code' => $newCode,
                        'account_code_sort' => $sort,
                    ]);
                }
            });
        } else {
            foreach ($rows as $row) {
                $c = trim((string) $row->account_code);
                $sort = AccountCodeScheme::sortKey($c);
                DB::table('chart_of_accounts')->where('id', $row->id)->update(['account_code_sort' => $sort]);
            }
        }

        foreach ([0, 1] as $officeId) {
            Cache::forget("coa_tree_{$orgId}_o{$officeId}_t0");
            Cache::forget("coa_tree_{$orgId}_o{$officeId}_t1");
        }
    }
}
