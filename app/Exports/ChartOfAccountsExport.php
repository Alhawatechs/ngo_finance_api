<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use App\Services\AccountCodeScheme;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel layout aligned with the standard "chart of account" workbook.
 * Columns are configurable via {@see self::ALLOWED_COLUMNS}.
 */
class ChartOfAccountsExport implements FromCollection, WithEvents, WithHeadings, WithMapping, WithProperties, WithTitle
{
    /** How many leading columns stay visible when scrolling horizontally (A.. = first N columns). */
    private const FROZEN_LEADING_COLUMNS = 3;

    /** @var list<string> */
    public const ALLOWED_COLUMNS = [
        'chart_of_accounts',
        'general_ledger_account',
        'account_code',
        'account_name',
        'account_type',
        'account_nature',
        'currency',
        'balance',
        'status',
        'description',
        'remark',
    ];

    /** @var list<string> */
    public const DEFAULT_COLUMNS = self::ALLOWED_COLUMNS;

    private Collection $byId;

    /** @var list<string> */
    private array $columnKeys;

    /**
     * @param  Collection<int, ChartOfAccount>  $accounts
     * @param  list<string>|null  $columnKeys
     */
    public function __construct(
        private Collection $accounts,
        ?array $columnKeys = null,
        private string $defaultCurrency = 'AFN',
        /** When true (Excel .xlsx), apply layout from the standard NGO workbook: blank row under headers, freeze panes, etc. */
        private bool $excelPresentation = true
    ) {
        $this->byId = $accounts->keyBy('id');
        $this->columnKeys = self::normalizeColumnKeys($columnKeys);
    }

    /** Sheet tab name — matches the standard NGO chart workbook. */
    public function title(): string
    {
        return 'Chart of Accounts';
    }

    /** Document metadata for the exported workbook (File → Info in Excel). */
    public function properties(): array
    {
        return [
            'creator' => 'AADA ERP Finance',
            'subject' => 'Chart of accounts',
            'keywords' => 'chart of accounts, accounting, general ledger, export',
            'description' => 'Hierarchical chart of accounts with balances, rollup formulas, and formatting.',
        ];
    }

    /**
     * @param  list<string>|null  $input
     * @return list<string>
     */
    public static function normalizeColumnKeys(?array $input): array
    {
        if ($input === null || $input === []) {
            return self::DEFAULT_COLUMNS;
        }

        $allowed = array_flip(self::ALLOWED_COLUMNS);
        $out = [];
        foreach ($input as $k) {
            if (! is_string($k)) {
                continue;
            }
            if (isset($allowed[$k]) && ! in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out !== [] ? $out : self::DEFAULT_COLUMNS;
    }

    /**
     * Depth-first order: finish one top-level category (and all descendants) before the next.
     *
     * @param  Collection<int, ChartOfAccount>  $accounts
     * @return Collection<int, ChartOfAccount>
     */
    public static function sortDepthFirst(Collection $accounts): Collection
    {
        if ($accounts->isEmpty()) {
            return $accounts;
        }

        $ids = $accounts->keyBy('id');
        $byParent = [];

        foreach ($accounts as $a) {
            $pid = $a->parent_id;
            $key = ($pid !== null && $ids->has($pid)) ? (int) $pid : 'root';
            $byParent[$key] ??= [];
            $byParent[$key][] = $a;
        }

        $sortFn = static function (ChartOfAccount $a, ChartOfAccount $b): int {
            return AccountCodeScheme::compare(
                (string) ($a->account_code ?? ''),
                (string) ($b->account_code ?? '')
            );
        };

        $sorted = [];
        $visit = static function ($key) use (&$visit, &$sorted, &$byParent, $sortFn): void {
            $nodes = $byParent[$key] ?? [];
            usort($nodes, $sortFn);
            foreach ($nodes as $node) {
                $sorted[] = $node;
                $visit($node->id);
            }
        };
        $visit('root');

        return collect($sorted);
    }

    public function collection(): Collection
    {
        return $this->accounts;
    }

    public function headings(): array
    {
        return array_map(fn (string $key) => $this->columnLabel($key), $this->columnKeys);
    }

    private function columnLabel(string $key): string
    {
        $dc = strtoupper($this->defaultCurrency ?: 'AFN');

        return match ($key) {
            'chart_of_accounts' => 'Chart of Accounts',
            'general_ledger_account' => 'General Ledger Account',
            'account_code' => 'Account Code',
            'account_name' => 'Account Name',
            'account_type' => 'Type',
            'account_nature' => 'Account Nature',
            'currency' => 'Currency',
            'balance' => 'Balance ('.$dc.')',
            'status' => 'Status',
            'description' => 'Description',
            'remark' => 'Remark',
            default => $key,
        };
    }

    /**
     * @param  ChartOfAccount  $account
     */
    public function map($account): array
    {
        $full = $this->rowValuesForAccount($account);

        return array_map(function (string $k) use ($full) {
            return $full[$k] ?? '';
        }, $this->columnKeys);
    }

    /**
     * @return array<string, float|int|string>
     */
    private function rowValuesForAccount(ChartOfAccount $account): array
    {
        if (! $account->is_posting) {
            // Matches "AADA Final Chart of accounts.xlsx": folder rows show currency + 0 balance like leaf rows.
            $path = $this->hierarchyPathFor($account);

            return [
                'chart_of_accounts' => $path,
                'general_ledger_account' => '',
                'account_code' => '',
                'account_name' => '',
                'account_type' => $this->accountTypeLabelForExport($account),
                'account_nature' => $this->accountNatureLabel($account),
                'currency' => $this->currencyForHeaderExport($account, $path),
                'balance' => 0.0,
                'status' => $this->statusLabel($account),
                'description' => (string) ($account->description ?? ''),
                'remark' => '',
            ];
        }

        $parentId = $account->parent_id ? (int) $account->parent_id : null;
        $parent = $parentId ? $this->byId->get($parentId) : null;
        $glAccount = $parent
            ? trim((string) $parent->account_name).': '.trim((string) $parent->account_code)
            : '';

        return [
            'chart_of_accounts' => '',
            'general_ledger_account' => $glAccount,
            'account_code' => (string) ($account->account_code ?? ''),
            'account_name' => (string) ($account->account_name ?? ''),
            'account_type' => $this->accountTypeLabelForExport($account),
            'account_nature' => $this->accountNatureLabel($account),
            'currency' => $this->currencyForExport($account),
            'balance' => (float) ($account->opening_balance ?? 0),
            'status' => $this->statusLabel($account),
            'description' => (string) ($account->description ?? ''),
            'remark' => '',
        ];
    }

    /**
     * Display labels aligned with the NGO chart workbook: non-posting expense headers use "Expenses";
     * posting expense accounts use "Expense". Other types use singular Title Case.
     */
    private function accountTypeLabelForExport(ChartOfAccount $account): string
    {
        $t = strtolower(trim((string) ($account->account_type ?? '')));
        if ($t === '') {
            return '';
        }
        if ($account->is_posting) {
            return match ($t) {
                'expense' => 'Expense',
                'revenue' => 'Revenue',
                'asset' => 'Asset',
                'liability' => 'Liability',
                'equity' => 'Equity',
                default => ucfirst($t),
            };
        }

        return match ($t) {
            'expense' => 'Expenses',
            'revenue' => 'Revenue',
            'asset' => 'Asset',
            'liability' => 'Liability',
            'equity' => 'Equity',
            default => ucfirst($t),
        };
    }

    private function statusLabel(ChartOfAccount $account): string
    {
        if ($account->deleted_at) {
            return 'Deleted';
        }

        return $account->is_active ? 'Active' : 'Inactive';
    }

    private function currencyForExport(ChartOfAccount $account): string
    {
        $c = strtoupper(trim((string) ($account->currency_code ?? '')));

        return $c !== '' ? $c : strtoupper($this->defaultCurrency);
    }

    /**
     * Folder/header rows in the reference workbook repeat currency and 0 balance; currency follows
     * {@see ChartOfAccount::$currency_code}, else the last (XXX) marker in the path/name, else org default.
     */
    private function currencyForHeaderExport(ChartOfAccount $account, ?string $hierarchyPath = null): string
    {
        $c = strtoupper(trim((string) ($account->currency_code ?? '')));
        if ($c !== '') {
            return $c;
        }
        $path = $hierarchyPath ?? $this->hierarchyPathFor($account);
        if ($path !== '' && preg_match_all('/\((?<ccy>[A-Z]{3})\)/', $path, $m) && $m['ccy'] !== []) {
            return strtoupper((string) end($m['ccy']));
        }
        $name = trim((string) ($account->account_name ?? ''));
        if ($name !== '' && preg_match_all('/\((?<ccy>[A-Z]{3})\)/', $name, $m) && $m['ccy'] !== []) {
            return strtoupper((string) end($m['ccy']));
        }

        return strtoupper($this->defaultCurrency ?: 'AFN');
    }

    /**
     * Path like the standard workbook: L1 "{code} · {name}", deeper "{code} . {name}", levels joined by ": ".
     */
    private function hierarchyPathFor(ChartOfAccount $account): string
    {
        $chain = [];
        $current = $account;
        $guard = 0;
        while ($current && $guard++ < 64) {
            array_unshift($chain, $current);
            $pid = $current->parent_id;
            $current = $pid ? $this->byId->get((int) $pid) : null;
        }

        $parts = [];
        foreach ($chain as $i => $node) {
            $code = trim((string) ($node->account_code ?? ''));
            $name = trim((string) ($node->account_name ?? ''));
            if ($i === 0) {
                $parts[] = trim($code.' · '.$name);
            } else {
                $parts[] = trim($code.' . '.$name);
            }
        }

        return implode(': ', $parts);
    }

    private function accountNatureLabel(ChartOfAccount $account): string
    {
        $nb = strtolower(trim((string) ($account->normal_balance ?? '')));

        return match ($nb) {
            'debit' => 'Debit',
            'credit' => 'Credit',
            default => ucfirst($nb ?: ''),
        };
    }

    private function lastColumnLetter(): string
    {
        $n = count($this->columnKeys);

        return Coordinate::stringFromColumnIndex($n);
    }

    /**
     * Set column widths from content (uses formatted display for numbers). Tuned for 9 pt Trebuchet MS.
     */
    private function fitColumnWidths(Worksheet $sheet, int $highestRow): void
    {
        $colCount = count($this->columnKeys);
        for ($i = 0; $i < $colCount; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $key = $this->columnKeys[$i];
            $maxLen = 0;
            for ($row = 1; $row <= $highestRow; $row++) {
                $maxLen = max($maxLen, $this->displayTextLengthForWidth($sheet, $letter, $row));
            }

            $minW = $this->minWidthForKey($key);
            $maxW = $this->maxWidthForKey($key);
            $mult = $this->widthMultiplierForKey($key);
            $pad = $this->widthPaddingForKey($key);
            $width = min($maxW, max($minW, $maxLen * $mult + $pad));
            $sheet->getColumnDimension($letter)->setAutoSize(false);
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
    }

    /**
     * Length of cell text as shown in Excel (commas on numbers, etc.).
     */
    private function displayTextLengthForWidth(Worksheet $sheet, string $letter, int $row): int
    {
        $cell = $sheet->getCell($letter.$row);
        $str = '';
        try {
            $fv = $cell->getFormattedValue();
            if ($fv !== null && $fv !== '') {
                $str = (string) $fv;
            }
        } catch (\Throwable) {
            // use raw value
        }
        if ($str === '') {
            $v = $cell->getValue();
            if ($v !== null && $v !== '') {
                $str = is_scalar($v) ? (string) $v : '';
            }
        }

        return mb_strlen($str, 'UTF-8');
    }

    private function minWidthForKey(string $key): float
    {
        return match ($key) {
            'chart_of_accounts' => 32,
            'general_ledger_account' => 24,
            'account_code' => 11,
            'account_name' => 20,
            'account_type' => 10,
            'account_nature' => 12,
            'currency' => 9,
            'balance' => 13,
            'status' => 10,
            'description' => 24,
            'remark' => 10,
            default => 11,
        };
    }

    private function maxWidthForKey(string $key): float
    {
        return match ($key) {
            'chart_of_accounts' => 92,
            'general_ledger_account' => 78,
            'account_name' => 52,
            'description' => 88,
            'account_code' => 18,
            'account_type' => 16,
            'account_nature' => 14,
            'currency' => 11,
            'balance' => 20,
            'status' => 14,
            'remark' => 36,
            default => 22,
        };
    }

    private function widthMultiplierForKey(string $key): float
    {
        return match ($key) {
            'chart_of_accounts', 'general_ledger_account', 'description' => 1.22,
            'account_name' => 1.18,
            'balance' => 1.08,
            'account_code', 'currency', 'status', 'account_type', 'account_nature' => 1.06,
            default => 1.12,
        };
    }

    private function widthPaddingForKey(string $key): float
    {
        return match ($key) {
            'chart_of_accounts', 'description' => 4.0,
            'general_ledger_account', 'account_name' => 3.5,
            'balance' => 2.0,
            default => 3.0,
        };
    }

    private function applyColumnAlignment(Worksheet $sheet, int $highestRow, string $key, string $horizontal): void
    {
        $idx = array_search($key, $this->columnKeys, true);
        if ($idx === false) {
            return;
        }
        $letter = Coordinate::stringFromColumnIndex($idx + 1);
        $sheet->getStyle($letter.'1:'.$letter.$highestRow)->getAlignment()
            ->setHorizontal($horizontal)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * Freeze the first N columns only (horizontal scroll keeps Chart of Accounts / GL / Code visible).
     */
    private function freezeLeadingColumnsOnly(Worksheet $sheet): void
    {
        $colCount = count($this->columnKeys);
        if ($colCount <= 1) {
            return;
        }
        $n = min(self::FROZEN_LEADING_COLUMNS, $colCount - 1);
        $firstScrollable = $n + 1;
        $sheet->freezePane(Coordinate::stringFromColumnIndex($firstScrollable).'1');
    }

    /**
     * Row background / font colors by account type (folder rows); posting rows stay neutral.
     *
     * @return array{fill: string, font: string}
     */
    private function fillRgbForDataRow(ChartOfAccount $account): array
    {
        if ($account->is_posting) {
            return ['fill' => 'FFFFFF', 'font' => '333333'];
        }

        $t = strtolower(trim((string) ($account->account_type ?? '')));

        return match ($t) {
            'revenue' => ['fill' => 'E2EFDA', 'font' => '1B4332'],
            'expense' => ['fill' => 'FCE4D6', 'font' => '6A1B0F'],
            'asset' => ['fill' => 'DDEBF7', 'font' => '1F4E79'],
            'liability' => ['fill' => 'FFF2CC', 'font' => '806000'],
            'equity' => ['fill' => 'E2D5EE', 'font' => '512E5F'],
            default => ['fill' => 'F2F2F2', 'font' => '333333'],
        };
    }

    /**
     * Folder balances = SUM of direct child balance cells; grand total = SUM of posting rows only (no double-count).
     *
     * @param  list<ChartOfAccount>  $accountsArray
     */
    private function applyBalanceFormulasAndGrandTotal(
        Worksheet $sheet,
        array $accountsArray,
        string $balanceLetter,
        int $firstDataRow,
        string $lastCol,
        int $balanceIdx
    ): int {
        $n = count($accountsArray);
        for ($i = 0; $i < $n; $i++) {
            $account = $accountsArray[$i];
            $excelRow = $firstDataRow + $i;
            if ($account->is_posting) {
                continue;
            }
            $parentId = (int) $account->id;
            $childIndices = [];
            for ($j = 0; $j < $n; $j++) {
                if ((int) ($accountsArray[$j]->parent_id ?? 0) === $parentId) {
                    $childIndices[] = $j;
                }
            }
            if ($childIndices === []) {
                $sheet->setCellValue($balanceLetter.$excelRow, '=0');

                continue;
            }
            $refs = array_map(fn (int $idx) => $balanceLetter.($firstDataRow + $idx), $childIndices);
            $sheet->setCellValue($balanceLetter.$excelRow, '=SUM('.implode(',', $refs).')');
        }

        $postingRefs = [];
        for ($i = 0; $i < $n; $i++) {
            if ($accountsArray[$i]->is_posting) {
                $postingRefs[] = $balanceLetter.($firstDataRow + $i);
            }
        }
        $footerRow = (int) $sheet->getHighestRow() + 1;
        $sheet->setCellValue('A'.$footerRow, 'Grand Total');
        if ($balanceIdx >= 2) {
            $mergeEnd = Coordinate::stringFromColumnIndex($balanceIdx);
            $sheet->mergeCells('A'.$footerRow.':'.$mergeEnd.$footerRow);
        }
        $sheet->getStyle('A'.$footerRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);

        if ($postingRefs === []) {
            $sheet->setCellValue($balanceLetter.$footerRow, '=0');
        } else {
            $grandFormula = '=SUM('.implode(',', $postingRefs).')';
            // Excel formula length limit is 8192 chars; fall back to a static sum if needed.
            if (strlen($grandFormula) > 7800) {
                $sum = 0.0;
                foreach ($accountsArray as $a) {
                    if ($a->is_posting) {
                        $sum += (float) ($a->opening_balance ?? 0);
                    }
                }
                $sheet->setCellValue($balanceLetter.$footerRow, $sum);
            } else {
                $sheet->setCellValue($balanceLetter.$footerRow, $grandFormula);
            }
        }

        $footerRange = 'A'.$footerRow.':'.$lastCol.$footerRow;
        $sheet->getStyle($footerRange)->getFont()->setBold(true);
        $sheet->getStyle($footerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E7E6E6');
        $sheet->getStyle($footerRange)->getFont()->getColor()->setRGB('000000');
        $sheet->getStyle($balanceLetter.$footerRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($balanceLetter.$footerRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
        $sheet->getStyle('A'.$footerRow.':'.$lastCol.$footerRow)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

        return $footerRow;
    }

    /**
     * Light gridlines across the used range (Excel only).
     */
    private function applyThinGridBorders(Worksheet $sheet, int $lastRow, string $lastCol): void
    {
        $sheet->getStyle('A1:'.$lastCol.$lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D0D0D0'],
                ],
            ],
        ]);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);

                // Reference workbook: row 1 = headers, row 2 = blank, data from row 3 (CSV has no blank row).
                if ($this->excelPresentation) {
                    $sheet->insertNewRowBefore(2, 1);
                    $sheet->getDefaultRowDimension()->setRowHeight(14.7);
                    $this->freezeLeadingColumnsOnly($sheet);
                }
                $firstDataRow = $this->excelPresentation ? 3 : 2;
                $accountCount = $this->accounts->count();
                $lastDataRow = $accountCount > 0 ? $firstDataRow + $accountCount - 1 : $firstDataRow - 1;

                $highestRow = (int) $sheet->getHighestRow();
                $lastCol = $this->lastColumnLetter();
                $range = 'A1:'.$lastCol.$highestRow;
                $baseFont = $sheet->getStyle($range)->getFont();
                $baseFont->setName('Trebuchet MS');
                $baseFont->setSize(9);

                $headerFont = $sheet->getStyle('A1:'.$lastCol.'1')->getFont();
                $headerFont->setName('Trebuchet MS');
                $headerFont->setSize(9);
                $headerFont->setBold(true);

                if ($this->excelPresentation) {
                    $sheet->getStyle('A1:'.$lastCol.'1')->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('4472C4');
                    $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->getColor()->setRGB('FFFFFF');
                    $sheet->getStyle('A2:'.$lastCol.'2')->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F2F2F2');
                }

                $rowIndex = $firstDataRow;
                foreach ($this->accounts as $account) {
                    $level = (int) ($account->level ?? 0);
                    $rowRange = 'A'.$rowIndex.':'.$lastCol.$rowIndex;
                    if ($this->excelPresentation) {
                        $palette = $this->fillRgbForDataRow($account);
                        $sheet->getStyle($rowRange)->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($palette['fill']);
                        $sheet->getStyle($rowRange)->getFont()->getColor()->setRGB($palette['font']);
                        if ($account->is_posting && $account->is_active && $account->deleted_at === null) {
                            $stripe = (($rowIndex - $firstDataRow) % 2) === 1;
                            if ($stripe) {
                                $sheet->getStyle($rowRange)->getFill()
                                    ->setFillType(Fill::FILL_SOLID)
                                    ->getStartColor()->setRGB('F2F2F2');
                                $sheet->getStyle($rowRange)->getFont()->getColor()->setRGB('333333');
                            }
                        }
                    }
                    if ($level === 1 || $level === 2) {
                        $rowFont = $sheet->getStyle($rowRange)->getFont();
                        $rowFont->setName('Trebuchet MS');
                        $rowFont->setSize(9);
                        $rowFont->setBold(true);
                    }
                    if ($this->excelPresentation && (! $account->is_active || $account->deleted_at)) {
                        $sheet->getStyle($rowRange)->getFont()->setItalic(true)->getColor()->setRGB('888888');
                    }
                    $rowIndex++;
                }

                $coaIdx = array_search('chart_of_accounts', $this->columnKeys, true);
                $dataEndRow = $lastDataRow >= $firstDataRow ? $lastDataRow : $highestRow;
                if ($coaIdx !== false && $lastDataRow >= $firstDataRow) {
                    $coaLetter = Coordinate::stringFromColumnIndex($coaIdx + 1);
                    $sheet->getStyle($coaLetter.$firstDataRow.':'.$coaLetter.$lastDataRow)->getAlignment()
                        ->setWrapText(true)
                        ->setVertical(Alignment::VERTICAL_CENTER);
                    if ($this->excelPresentation) {
                        $r = $firstDataRow;
                        foreach ($this->accounts as $account) {
                            $indent = max(0, min(6, (int) ($account->level ?? 0) - 1));
                            $sheet->getStyle($coaLetter.$r)->getAlignment()
                                ->setWrapText(true)
                                ->setIndent($indent)
                                ->setVertical(Alignment::VERTICAL_CENTER);
                            $r++;
                        }
                    }
                }

                $this->applyColumnAlignment($sheet, $dataEndRow, 'account_code', Alignment::HORIZONTAL_CENTER);
                $this->applyColumnAlignment($sheet, $dataEndRow, 'currency', Alignment::HORIZONTAL_CENTER);
                $this->applyColumnAlignment($sheet, $dataEndRow, 'balance', Alignment::HORIZONTAL_RIGHT);
                $this->applyColumnAlignment($sheet, $dataEndRow, 'status', Alignment::HORIZONTAL_CENTER);

                $balanceIdx = array_search('balance', $this->columnKeys, true);
                if ($balanceIdx !== false) {
                    $letter = Coordinate::stringFromColumnIndex($balanceIdx + 1);
                    $sheet->getStyle($letter.$firstDataRow.':'.$letter.$dataEndRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                }

                if ($this->excelPresentation && $lastDataRow >= $firstDataRow) {
                    $sheet->setAutoFilter('A1:'.$lastCol.$lastDataRow);
                }

                $footerRow = null;
                if ($this->excelPresentation && $balanceIdx !== false) {
                    $balanceLetter = Coordinate::stringFromColumnIndex($balanceIdx + 1);
                    $accountsArray = $this->accounts->values()->all();
                    $footerRow = $this->applyBalanceFormulasAndGrandTotal(
                        $sheet,
                        $accountsArray,
                        $balanceLetter,
                        $firstDataRow,
                        $lastCol,
                        $balanceIdx
                    );
                    $highestRow = (int) $sheet->getHighestRow();
                }

                if ($this->excelPresentation) {
                    $highestRow = (int) $sheet->getHighestRow();
                    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 2);
                    $sheet->getPageSetup()->setPrintArea('A1:'.$lastCol.$highestRow);
                    $sheet->getTabColor()->setRGB('4472C4');
                    $this->applyThinGridBorders($sheet, $highestRow, $lastCol);
                    $sheet->getStyle('A1:'.$lastCol.'1')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('305496');
                    if ($footerRow !== null) {
                        $sheet->getStyle('A'.$footerRow.':'.$lastCol.$footerRow)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
                    }
                }

                $this->fitColumnWidths($sheet, (int) $sheet->getHighestRow());
            },
        ];
    }
}
