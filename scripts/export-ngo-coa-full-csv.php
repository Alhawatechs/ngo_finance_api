<?php

/**
 * Full NGO chart CSV: L1–L3 headers from NGOChartOfAccountsSeeder, L4 from getAccounts()
 * plus 21.1.* (ProgramPersonnelJobTitlesSeeder) and 21.2.* (SalariesHFsJobTitlesSeeder).
 * Rows sorted by AccountCodeScheme (same order as the app). No DB required.
 *
 * Usage: php backend/scripts/export-ngo-coa-full-csv.php [output-path]
 * Default: docs/ngo-coa-full-chart-of-accounts.csv (repo root relative to backend/)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\AccountCodeScheme;
use Database\Seeders\NGOChartOfAccountsSeeder;
use Database\Seeders\ProgramPersonnelJobTitlesSeeder;
use Database\Seeders\SalariesHFsJobTitlesSeeder;

$out = $argv[1] ?? dirname(__DIR__, 2) . '/docs/ngo-coa-full-chart-of-accounts.csv';

$rows = [];

foreach (NGOChartOfAccountsSeeder::mainCategoryDefinitions() as $h) {
    $rows[] = [
        'level' => 1,
        'account_code' => $h['code'],
        'account_name' => $h['name'],
        'parent_code' => '',
        'account_type' => $h['type'],
        'normal_balance' => $h['balance'],
        'is_header' => '1',
        'is_posting' => '0',
        'description' => $h['description'] ?? '',
        'is_bank' => '',
        'is_cash' => '',
        'fund_type' => '',
    ];
}

foreach (NGOChartOfAccountsSeeder::subHeaderDefinitions() as $h) {
    $rows[] = [
        'level' => 2,
        'account_code' => $h['code'],
        'account_name' => $h['name'],
        'parent_code' => $h['parent'],
        'account_type' => $h['type'],
        'normal_balance' => $h['balance'],
        'is_header' => '1',
        'is_posting' => '0',
        'description' => '',
        'is_bank' => '',
        'is_cash' => '',
        'fund_type' => '',
    ];
}

foreach (NGOChartOfAccountsSeeder::level3HeaderDefinitions() as $h) {
    $rows[] = [
        'level' => 3,
        'account_code' => $h['code'],
        'account_name' => $h['name'],
        'parent_code' => $h['parent'],
        'account_type' => $h['type'],
        'normal_balance' => $h['balance'],
        'is_header' => '1',
        'is_posting' => '0',
        'description' => '',
        'is_bank' => '',
        'is_cash' => '',
        'fund_type' => '',
    ];
}

foreach (NGOChartOfAccountsSeeder::getAccounts() as $row) {
    $code = $row['code'];
    $rows[] = [
        'level' => 4,
        'account_code' => $code,
        'account_name' => $row['name'] ?? '',
        'parent_code' => $row['parent_code'] ?? '',
        'account_type' => $row['type'] ?? '',
        'normal_balance' => $row['balance'] ?? '',
        'is_header' => '0',
        'is_posting' => '1',
        'description' => $row['description'] ?? '',
        'is_bank' => ! empty($row['is_bank']) ? '1' : '',
        'is_cash' => ! empty($row['is_cash']) ? '1' : '',
        'fund_type' => $row['fund_type'] ?? '',
    ];
}

foreach (ProgramPersonnelJobTitlesSeeder::jobTitles() as $i => $name) {
    $n = (int) $i + 1;
    $rows[] = [
        'level' => 4,
        'account_code' => '21.1.'.$n,
        'account_name' => $name,
        'parent_code' => '21.1',
        'account_type' => 'expense',
        'normal_balance' => 'debit',
        'is_header' => '0',
        'is_posting' => '1',
        'description' => 'Salary/compensation for '.$name,
        'is_bank' => '',
        'is_cash' => '',
        'fund_type' => '',
    ];
}

foreach (SalariesHFsJobTitlesSeeder::jobTitles() as $i => $name) {
    $n = (int) $i + 1;
    $rows[] = [
        'level' => 4,
        'account_code' => '21.2.'.$n,
        'account_name' => $name,
        'parent_code' => '21.2',
        'account_type' => 'expense',
        'normal_balance' => 'debit',
        'is_header' => '0',
        'is_posting' => '1',
        'description' => 'Salary for '.$name,
        'is_bank' => '',
        'is_cash' => '',
        'fund_type' => '',
    ];
}

usort($rows, static function (array $a, array $b): int {
    return AccountCodeScheme::compare($a['account_code'], $b['account_code']);
});

$fh = fopen($out, 'wb');
if ($fh === false) {
    fwrite(STDERR, "Cannot write: {$out}\n");
    exit(1);
}

$header = ['level', 'account_code', 'account_name', 'parent_code', 'account_type', 'normal_balance', 'is_header', 'is_posting', 'description', 'is_bank', 'is_cash', 'fund_type'];
fputcsv($fh, $header);
foreach ($rows as $r) {
    fputcsv($fh, [
        $r['level'],
        $r['account_code'],
        $r['account_name'],
        $r['parent_code'],
        $r['account_type'],
        $r['normal_balance'],
        $r['is_header'],
        $r['is_posting'],
        $r['description'],
        $r['is_bank'],
        $r['is_cash'],
        $r['fund_type'],
    ]);
}
fclose($fh);

echo 'Wrote '.count($rows)." rows to {$out}\n";
