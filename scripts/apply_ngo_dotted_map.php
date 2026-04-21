<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CoaDottedMigrationService;
use Illuminate\Support\Collection;

$id = 1;
$byCode = [];
$rows = [];

$add = function (string $code, string $type, ?string $parentCode) use (&$rows, &$id, &$byCode) {
    $pid = null;
    if ($parentCode !== null) {
        if (! isset($byCode[$parentCode])) {
            throw new RuntimeException("Parent {$parentCode} not found for {$code}");
        }
        $pid = $byCode[$parentCode];
    }
    $byCode[$code] = $id;
    $rows[] = (object) [
        'id' => $id,
        'organization_id' => 1,
        'parent_id' => $pid,
        'account_code' => $code,
        'account_type' => $type,
    ];
    $id++;
};

foreach ([['10000', 'revenue'], ['20000', 'expense'], ['30000', 'asset'], ['40000', 'liability'], ['50000', 'equity']] as $x) {
    $add($x[0], $x[1], null);
}
foreach ([
    ['30100', 'asset', '30000'], ['30200', 'asset', '30000'],
    ['40100', 'liability', '40000'], ['40200', 'liability', '40000'],
    ['11000', 'revenue', '10000'],
    ['21000', 'expense', '20000'], ['22000', 'expense', '20000'], ['23000', 'expense', '20000'], ['24000', 'expense', '20000'],
] as $h) {
    $add($h[0], $h[1], $h[2]);
}
foreach ([
    ['30110', 'asset', '30100'], ['30120', 'asset', '30100'],
    ['30210', 'asset', '30200'], ['30220', 'asset', '30200'],
    ['40110', 'liability', '40100'], ['40120', 'liability', '40100'], ['40130', 'liability', '40100'],
    ['40210', 'liability', '40200'],
    ['50100', 'equity', '50000'], ['50200', 'equity', '50000'], ['50300', 'equity', '50000'],
    ['11100', 'revenue', '11000'], ['11200', 'revenue', '11000'], ['11300', 'revenue', '11000'],
    ['21100', 'expense', '21000'], ['21200', 'expense', '21000'],
    ['22100', 'expense', '22000'], ['22200', 'expense', '22000'], ['22300', 'expense', '22000'],
    ['22400', 'expense', '22000'], ['22500', 'expense', '22000'], ['22600', 'expense', '22000'],
    ['22700', 'expense', '22000'], ['22800', 'expense', '22000'], ['22900', 'expense', '22000'],
    ['21300', 'expense', '21000'], ['21400', 'expense', '21000'], ['21500', 'expense', '21000'],
    ['23100', 'expense', '23000'], ['24100', 'expense', '24000'], ['24200', 'expense', '24000'],
] as $h) {
    $add($h[0], $h[1], $h[2]);
}

$path = __DIR__.'/../database/seeders/NGOChartOfAccountsSeeder.php';
$src = file_get_contents($path);
if (! preg_match('/(?:public static|private) function getAccounts\(\): array\s*\{([\s\S]*?)\n\s*\}\s*\n/', $src, $m)) {
    fwrite(STDERR, "parse getAccounts failed\n");
    exit(1);
}
$block = $m[1];
preg_match_all("/'code' => '(\d+)'[^\\]]*'type' => '(asset|liability|revenue|expense|equity)'[^\\]]*'parent_code' => '(\d+)'/", $block, $triples, PREG_SET_ORDER);
foreach ($triples as $t) {
    $add($t[1], $t[2], $t[3]);
}

$migrator = new CoaDottedMigrationService;
$map = $migrator->buildIdToNewCode(collect($rows));

$oldToNew = [];
foreach ($rows as $r) {
    $oldToNew[$r->account_code] = $map[$r->id];
}

uksort($oldToNew, fn ($a, $b) => strlen($b) <=> strlen($a));

$out = file_get_contents($path);
foreach ($oldToNew as $old => $new) {
    $out = str_replace("'".$old."'", "'".$new."'", $out);
}

file_put_contents($path, $out);
echo "Updated NGOChartOfAccountsSeeder.php with ".count($oldToNew)." code mappings.\n";
