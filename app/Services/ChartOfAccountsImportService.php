<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use App\Support\ChartOfAccountsCache;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Parses CSV / Excel (Sample format) and creates chart of accounts rows in code order.
 */
final class ChartOfAccountsImportService
{
    private const MAX_ROWS = 2000;

    /**
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     errors: array<int, array{row: int|null, account_code: string|null, message: string}>,
     *     diagnostics: array{phase: string, code: string, message: string, hint: string, actions?: list<string>, details?: array<string, mixed>}|null
     * }
     */
    public function run(UploadedFile $file, int $organizationId): array
    {
        $parsed = $this->parseFileWithDiagnostics($file);
        if ($parsed['diagnostic'] !== null) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'diagnostics' => $parsed['diagnostic'],
            ];
        }

        $rows = $parsed['rows'];
        if ($rows === []) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'diagnostics' => $this->diagnostic(
                    'format',
                    'NO_DATA_ROWS',
                    'No account rows were found after the header row.',
                    'The parser found a valid header but no usable data lines.',
                    [
                        'Add at least one row below the header with both Account Code and Account Name filled in.',
                        'Delete blank rows between the header and your first data row.',
                        'If cells look filled in Excel, check for leading spaces or that values are not formulas that evaluate to empty.',
                        'Compare your layout to the “Sample format” sheet in the downloaded import workbook.',
                    ]
                ),
            ];
        }

        if (count($rows) > self::MAX_ROWS) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'diagnostics' => $this->diagnostic(
                    'format',
                    'TOO_MANY_ROWS',
                    'Too many data rows (max '.self::MAX_ROWS.').',
                    'Each import is limited to '.self::MAX_ROWS.' account lines for performance and safety.',
                    [
                        'Split your file into two or more CSV/Excel files, each under '.self::MAX_ROWS.' data rows (excluding the header).',
                        'Import the first file, then run import again for the next part.',
                        'Remove comment rows or totals at the bottom that are not real accounts.',
                    ]
                ),
            ];
        }

        $allowedCurrencies = Organization::getActiveCurrencyCodesForOrg($organizationId);

        $errors = [];
        $uniqueRows = [];
        $firstLineByCode = [];
        foreach ($rows as $r) {
            $c = $r['account_code'];
            if (! isset($firstLineByCode[$c])) {
                $firstLineByCode[$c] = $r['_row'];
                $uniqueRows[] = $r;
            } else {
                $errors[] = [
                    'row' => $r['_row'],
                    'account_code' => $c,
                    'message' => 'Duplicate account code in file (line '.$firstLineByCode[$c].' kept).',
                ];
            }
        }
        $rows = $uniqueRows;

        usort($rows, function ($a, $b) {
            return AccountCodeScheme::compare($a['account_code'], $b['account_code']);
        });

        $imported = 0;
        $skipped = 0;
        $codeToId = [];

        foreach ($rows as $row) {
            $code = $row['account_code'];
            $line = $row['_row'];

            if (ChartOfAccount::withTrashed()
                ->where('organization_id', $organizationId)
                ->where('account_code', $code)
                ->exists()) {
                $skipped++;

                continue;
            }

            $type = strtolower(trim($row['account_type']));
            $nb = strtolower(trim($row['normal_balance']));
            if (! in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
                $errors[] = ['row' => $line, 'account_code' => $code, 'message' => 'Type must be asset, liability, equity, revenue, or expense.'];

                continue;
            }
            if (! in_array($nb, ['debit', 'credit'], true)) {
                $errors[] = ['row' => $line, 'account_code' => $code, 'message' => 'Normal balance must be debit or credit.'];

                continue;
            }

            $parentCode = AccountCodeScheme::parentCodeForCode($code);
            $parentId = null;
            if ($parentCode !== null) {
                if (isset($codeToId[$parentCode])) {
                    $parentId = $codeToId[$parentCode];
                } else {
                    $parent = ChartOfAccount::where('organization_id', $organizationId)
                        ->where('account_code', $parentCode)
                        ->first();
                    if (! $parent) {
                        $errors[] = [
                            'row' => $line,
                            'account_code' => $code,
                            'message' => 'Parent account "'.$parentCode.'" not found. List parents before children in the file, or create parents first.',
                        ];

                        continue;
                    }
                    $parentId = $parent->id;
                }
            }

            $level = AccountCodeScheme::levelFromCode($code);
            if ($level === null) {
                $errors[] = ['row' => $line, 'account_code' => $code, 'message' => 'Invalid account code format.'];

                continue;
            }

            $isPosting = $level === 4;
            $isHeader = ! $isPosting;

            $currencyRaw = $row['currency_code'] ?? null;
            $trimmedCur = $currencyRaw !== null && $currencyRaw !== '' ? strtoupper(trim((string) $currencyRaw)) : null;
            if ($isPosting && $trimmedCur !== null && ! in_array($trimmedCur, $allowedCurrencies, true)) {
                $errors[] = [
                    'row' => $line,
                    'account_code' => $code,
                    'message' => 'Currency must be one of your organization\'s active currencies.',
                ];

                continue;
            }

            $currencyResolved = $trimmedCur;
            if ($isPosting && $currencyResolved === null) {
                $currencyResolved = Organization::find($organizationId)?->default_currency
                    ?? config('erp.currencies.default', 'AFN');
            }
            if ($isHeader || ! $isPosting) {
                $currencyResolved = null;
            }

            $payload = [
                'organization_id' => $organizationId,
                'parent_id' => $parentId,
                'account_code' => $code,
                'account_name' => $row['account_name'],
                'account_type' => $type,
                'normal_balance' => $nb,
                'is_header' => $isHeader,
                'is_posting' => $isPosting,
                'is_bank_account' => false,
                'is_cash_account' => false,
                'is_active' => true,
                'description' => $row['description'] ?? null,
                'currency_code' => $currencyResolved,
                'opening_balance' => $isPosting ? ($row['opening_balance'] ?? 0) : null,
                'opening_balance_date' => null,
            ];

            if ($parentId !== null) {
                $parent = ChartOfAccount::where('organization_id', $organizationId)->where('id', $parentId)->first();
                if ($parent) {
                    $payload['level'] = $parent->level + 1;
                    if ($payload['level'] > 4) {
                        $errors[] = ['row' => $line, 'account_code' => $code, 'message' => 'Maximum account level (4) exceeded.'];

                        continue;
                    }
                }
            } else {
                $payload['level'] = 1;
            }

            if ($parentId !== null) {
                $parent = ChartOfAccount::where('organization_id', $organizationId)->where('id', $parentId)->first();
                if ($parent && $parent->is_posting) {
                    $parent->update(['is_header' => true, 'is_posting' => false]);
                }
            }

            $finalCode = trim((string) $payload['account_code']);
            if (! AccountCodeScheme::isWellFormed($finalCode)) {
                $errors[] = ['row' => $line, 'account_code' => $code, 'message' => 'Account code is not well-formed (dotted scheme).'];

                continue;
            }
            if ($parentId !== null) {
                $p = ChartOfAccount::where('id', $parentId)->where('organization_id', $organizationId)->first();
                if ($p && ! AccountCodeScheme::isValidChildCode($finalCode, trim((string) $p->account_code))) {
                    $errors[] = ['row' => $line, 'account_code' => $code, 'message' => 'Account code must extend the parent code (e.g. under 11 use 11.1, 11.2, …).'];

                    continue;
                }
            } elseif (AccountCodeScheme::levelFromCode($finalCode) !== 1) {
                $errors[] = ['row' => $line, 'account_code' => $code, 'message' => 'Top-level account code must be a single digit 1–5.'];

                continue;
            }

            try {
                $account = ChartOfAccount::create($payload);
                $codeToId[$code] = $account->id;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $line,
                    'account_code' => $code,
                    'message' => $e->getMessage(),
                ];
            }
        }

        if ($imported > 0) {
            ChartOfAccountsCache::forgetForOrganization($organizationId);
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'diagnostics' => null];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, diagnostic: array{phase: string, code: string, message: string, hint: string}|null}
     */
    private function parseFileWithDiagnostics(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'upload',
                    'FILE_UNREADABLE',
                    'The uploaded file could not be read.',
                    'The server could not open the temporary upload.',
                    [
                        'Save the file again on your computer, then upload the new copy.',
                        'Close the file in Excel if it is open in exclusive mode, then retry.',
                        'If the problem persists, download our CSV sample and paste your data into it.',
                    ]
                ),
            ];
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: '');

        if (in_array($ext, ['xlsx', 'xls', 'xlsm'], true)) {
            return $this->parseSpreadsheetWithDiagnostics($path);
        }

        if (in_array($ext, ['csv', 'txt'], true)) {
            return $this->parseCsvWithDiagnostics($path);
        }

        return [
            'rows' => [],
            'diagnostic' => $this->diagnostic(
                'upload',
                'UNSUPPORTED_TYPE',
                'This file type is not supported for import.',
                'Only spreadsheet exports used for the chart of accounts can be processed.',
                [
                    'In Excel: File → Save As → choose “Excel Workbook (.xlsx)” or “CSV UTF-8”.',
                    'Do not upload PDF, Word, or images — export data as CSV or Excel.',
                    'Allowed extensions: .csv, .txt, .xlsx, .xls, .xlsm.',
                ]
            ),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, diagnostic: array{phase: string, code: string, message: string, hint: string}|null}
     */
    private function parseSpreadsheetWithDiagnostics(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'parse',
                    'EXCEL_READ_ERROR',
                    'The Excel file could not be opened. It may be corrupted, password-protected, or not a real spreadsheet.',
                    'PhpSpreadsheet could not read the file bytes as a workbook.',
                    [
                        'Remove password protection (File → Info → Protect Workbook) and save again.',
                        'Open the file in Excel and use Save As → .xlsx to a new file name, then upload that copy.',
                        'Download our import sample workbook and paste your table into the “Sample format” sheet.',
                        'Do not rename non-Excel files to .xlsx; the content must be a real Excel file.',
                    ]
                ),
            ];
        }

        $sheet = $this->findBestSheetForImport($spreadsheet);
        if ($sheet === null) {
            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'format',
                    'HEADERS_NOT_FOUND',
                    'No sheet contains the required column headers.',
                    'At least one row must contain both the phrases “Account Code” and “Account Name” (used to locate your table).',
                    [
                        'Download our Excel import sample and use the “Sample format” sheet as a template.',
                        'Or add a header row anywhere above your data with columns: Account Code, Account Name, Type, Normal Balance.',
                        'Check for typos: headers are matched case-insensitively but must include those words.',
                    ]
                ),
            ];
        }

        $rows = $sheet->toArray();
        if ($rows === []) {
            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'format',
                    'EMPTY_SHEET',
                    'The worksheet used for import is empty.',
                    'The selected sheet has no cells.',
                    [
                        'Switch to the sheet that contains your table (or paste data into “Sample format” in our sample workbook).',
                        'Ensure the sheet is not hidden without content — unhide or copy data to a visible sheet.',
                    ]
                ),
            ];
        }

        return $this->extractRowsFromGrid($rows);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, diagnostic: array{phase: string, code: string, message: string, hint: string}|null}
     */
    private function parseCsvWithDiagnostics(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'upload',
                    'FILE_READ_ERROR',
                    'The file could not be read from disk.',
                    'The temporary upload path was not readable.',
                    [
                        'Try uploading the same file again.',
                        'Save the file locally with a simple name (no special characters), then upload again.',
                    ]
                ),
            ];
        }
        if ($content === '') {
            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'format',
                    'EMPTY_FILE',
                    'The file is empty.',
                    'There are no bytes to parse.',
                    [
                        'Export your chart again from Excel or copy data into our CSV sample.',
                        'Ensure you did not save an empty sheet.',
                    ]
                ),
            ];
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $lines = preg_split("/\r\n|\n|\r/", $content);
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }
        if ($rows === []) {
            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'format',
                    'NO_ROWS',
                    'No rows were found in the CSV.',
                    'After removing blank lines, nothing remained to parse.',
                    [
                        'In Excel: Save As → CSV (Comma delimited) or CSV UTF-8.',
                        'Ensure the file has at least a header row and is not only empty lines.',
                    ]
                ),
            ];
        }

        return $this->extractRowsFromGrid($rows);
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, diagnostic: array{phase: string, code: string, message: string, hint: string}|null}
     */
    private function extractRowsFromGrid(array $rows): array
    {
        $headerIndex = $this->findHeaderRowIndex($rows);
        if ($headerIndex === null) {
            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'format',
                    'HEADERS_NOT_FOUND',
                    'Could not find a header row with “Account Code” and “Account Name”.',
                    'The system searches for a row that contains both phrases (case-insensitive).',
                    [
                        'Put a header row above your data with columns labeled Account Code and Account Name.',
                        'Do not merge cells across the header row; each label should sit in its own column.',
                        'Copy the header row from the “Sample format” sheet in our workbook.',
                    ]
                ),
            ];
        }

        $headers = array_map(fn ($h) => $this->normalizeHeaderCell($h), $rows[$headerIndex]);
        $map = $this->mapColumnIndices($headers);
        if (! $this->hasRequiredColumns($map)) {
            $missing = $this->missingRequiredColumnLabels($map);
            $actions = array_map(
                fn (string $label) => 'Add a column titled exactly: '.$label.' (see the import sample for spelling).',
                $missing
            );
            $actions[] = 'Required columns are: Account Code, Account Name, Type, Normal Balance. Optional: Currency, Opening Balance, Description.';

            return [
                'rows' => [],
                'diagnostic' => $this->diagnostic(
                    'format',
                    'HEADERS_INCOMPLETE',
                    'Required columns are missing: '.implode(', ', $missing).'.',
                    'Header names must match the import template (spacing and spelling).',
                    $actions,
                    ['missing_columns' => $missing]
                ),
            ];
        }

        $out = [];
        $lineNumber = $headerIndex + 1;
        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $lineNumber++;
            $line = $rows[$i];
            if (! $this->rowHasData($line)) {
                continue;
            }
            $parsed = $this->mapRowToAssoc($line, $map, $lineNumber);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }

        return ['rows' => $out, 'diagnostic' => null];
    }

    private function findBestSheetForImport(Spreadsheet $spreadsheet): ?Worksheet
    {
        $preferred = ['Chart of Accounts', 'Sample format'];
        foreach ($preferred as $name) {
            $sheet = $spreadsheet->getSheetByName($name);
            if ($sheet !== null && $this->sheetHasDataHeaders($sheet)) {
                return $sheet;
            }
        }
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($this->sheetHasDataHeaders($sheet)) {
                return $sheet;
            }
        }

        return null;
    }

    private function sheetHasDataHeaders(Worksheet $sheet): bool
    {
        $rows = $sheet->toArray();

        return $this->findHeaderRowIndex($rows) !== null;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function findHeaderRowIndex(array $rows): ?int
    {
        foreach ($rows as $i => $row) {
            $joined = strtolower(implode(' ', array_map(fn ($c) => (string) $c, $row)));
            if (str_contains($joined, 'account code') && str_contains($joined, 'account name')) {
                return $i;
            }
        }

        return null;
    }

    private function normalizeHeaderCell(mixed $h): string
    {
        $s = strtolower(trim((string) $h));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return $s;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private function mapColumnIndices(array $headers): array
    {
        $aliases = [
            'account code' => 'account_code',
            'account name' => 'account_name',
            'type' => 'account_type',
            'normal balance' => 'normal_balance',
            'account nature' => 'normal_balance',
            'currency' => 'currency_code',
            'opening balance' => 'opening_balance',
            'description' => 'description',
            'remark' => 'description',
            'balance (afn)' => 'opening_balance',
            'balance (usd)' => 'opening_balance',
        ];
        $map = [];
        foreach ($headers as $idx => $h) {
            $h = $this->normalizeHeaderCell($h);
            if (isset($aliases[$h])) {
                $map[$aliases[$h]] = $idx;

                continue;
            }
            if (preg_match('/^balance\s*\(/', $h) === 1 && ! isset($map['opening_balance'])) {
                $map['opening_balance'] = $idx;
            }
            if ($h === 'balance' && ! isset($map['opening_balance'])) {
                $map['opening_balance'] = $idx;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $map
     * @return list<string>
     */
    private function missingRequiredColumnLabels(array $map): array
    {
        $required = [
            'account_code' => 'Account Code',
            'account_name' => 'Account Name',
            'account_type' => 'Type',
            'normal_balance' => 'Normal Balance or Account Nature',
        ];
        $missing = [];
        foreach ($required as $key => $label) {
            if (! isset($map[$key])) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, int>  $map
     */
    private function hasRequiredColumns(array $map): bool
    {
        foreach (['account_code', 'account_name', 'account_type', 'normal_balance'] as $req) {
            if (! isset($map[$req])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $line
     */
    private function rowHasData(array $line): bool
    {
        foreach ($line as $cell) {
            if (trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $line
     * @param  array<string, int>  $map
     * @return array<string, mixed>|null
     */
    private function mapRowToAssoc(array $line, array $map, int $lineNumber): ?array
    {
        $code = trim((string) ($line[$map['account_code']] ?? ''));
        if ($code === '') {
            return null;
        }
        $name = trim((string) ($line[$map['account_name']] ?? ''));
        if ($name === '') {
            return null;
        }
        $type = $this->normalizeImportAccountType(trim((string) ($line[$map['account_type']] ?? '')));
        $nb = $this->normalizeImportNormalBalance(trim((string) ($line[$map['normal_balance']] ?? '')));

        $currency = isset($map['currency_code']) ? trim((string) ($line[$map['currency_code']] ?? '')) : '';
        $opening = 0.0;
        if (isset($map['opening_balance'])) {
            $raw = $line[$map['opening_balance']] ?? null;
            if ($raw !== null && $raw !== '') {
                $opening = (float) ($raw);
            }
        }
        $desc = isset($map['description']) ? trim((string) ($line[$map['description']] ?? '')) : '';

        return [
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $nb,
            'currency_code' => $currency !== '' ? $currency : null,
            'opening_balance' => $opening,
            'description' => $desc !== '' ? $desc : null,
            '_row' => $lineNumber,
        ];
    }

    private function normalizeImportAccountType(string $raw): string
    {
        $s = strtolower(trim($raw));

        return match ($s) {
            'expenses' => 'expense',
            'revenues' => 'revenue',
            'assets' => 'asset',
            'liabilities' => 'liability',
            default => $s,
        };
    }

    private function normalizeImportNormalBalance(string $raw): string
    {
        $s = strtolower(trim($raw));

        return match ($s) {
            'dr' => 'debit',
            'cr' => 'credit',
            default => $s,
        };
    }

    /**
     * @param  list<string>  $actions
     * @param  array<string, mixed>  $details
     * @return array{
     *     phase: string,
     *     code: string,
     *     message: string,
     *     hint: string,
     *     actions?: list<string>,
     *     details?: array<string, mixed>
     * }
     */
    private function diagnostic(string $phase, string $code, string $message, string $hint, array $actions = [], array $details = []): array
    {
        $out = [
            'phase' => $phase,
            'code' => $code,
            'message' => $message,
            'hint' => $hint,
        ];
        if ($actions !== []) {
            $out['actions'] = array_values($actions);
        }
        if ($details !== []) {
            $out['details'] = $details;
        }

        return $out;
    }
}
