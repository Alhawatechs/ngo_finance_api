<?php

/**
 * Export NGOChartOfAccountsSeeder::getAccounts() to CSV (no DB required).
 * Usage: php backend/scripts/export-ngo-coa-csv.php [output-path]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$rows = \Database\Seeders\NGOChartOfAccountsSeeder::getAccounts();
$out = $argv[1] ?? dirname(__DIR__, 2) . '/docs/ngo-coa-posting-accounts-from-ngo-seeder.csv';

$fh = fopen($out, 'wb');
if ($fh === false) {
    fwrite(STDERR, "Cannot write: {$out}\n");
    exit(1);
}

fputcsv($fh, ['account_code', 'account_name', 'parent_code', 'account_type', 'normal_balance', 'is_bank', 'is_cash', 'fund_type']);

foreach ($rows as $row) {
    fputcsv($fh, [
        $row['code'] ?? '',
        $row['name'] ?? '',
        $row['parent_code'] ?? '',
        $row['type'] ?? '',
        $row['balance'] ?? '',
        isset($row['is_bank']) && $row['is_bank'] ? '1' : '',
        isset($row['is_cash']) && $row['is_cash'] ? '1' : '',
        $row['fund_type'] ?? '',
    ]);
}

fclose($fh);
echo "Wrote " . count($rows) . " rows to {$out}\n";
