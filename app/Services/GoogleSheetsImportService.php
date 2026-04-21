<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleSheetsImportService
{
    protected string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('google_sheets.api_key', '');
    }

    /**
     * Extract spreadsheet ID from a Google Sheets URL.
     * Supports: https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/... or /d/SPREADSHEET_ID
     */
    public static function extractSpreadsheetIdFromUrl(string $url): ?string
    {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#^([a-zA-Z0-9_-]+)$#', trim($url), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Fetch spreadsheet metadata (all sheet names/tabs).
     */
    public function getSpreadsheetMetadata(string $spreadsheetId): array
    {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}";
        $response = Http::get($url, [
            'key' => $this->apiKey,
            'fields' => 'sheets(properties(sheetId,title))',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Google Sheets API error: ' . ($response->json('error.message') ?? $response->body()),
                $response->status()
            );
        }

        $data = $response->json();
        $sheets = $data['sheets'] ?? [];
        $result = [];
        foreach ($sheets as $sheet) {
            $props = $sheet['properties'] ?? [];
            $result[] = [
                'sheet_id' => $props['sheetId'] ?? null,
                'title' => $props['title'] ?? 'Sheet',
            ];
        }
        return $result;
    }

    /**
     * Fetch first row (header row) of a sheet. Sheet name must be quoted if it contains spaces.
     */
    public function getSheetHeaderRow(string $spreadsheetId, string $sheetTitle): array
    {
        $range = $this->buildRange($sheetTitle, '1:1');
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/" . rawurlencode($range);
        $response = Http::get($url, ['key' => $this->apiKey]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Google Sheets API error: ' . ($response->json('error.message') ?? $response->body()),
                $response->status()
            );
        }

        $values = $response->json('values');
        if (empty($values) || ! is_array($values[0] ?? null)) {
            return [];
        }
        return array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $values[0]);
    }

    /**
     * Build A1 range for a sheet. Quote sheet name if it contains spaces or special chars.
     */
    protected function buildRange(string $sheetTitle, string $a1): string
    {
        $needsQuotes = preg_match('/[\s\'",\\\]/', $sheetTitle);
        $safe = $needsQuotes ? "'" . str_replace("'", "''", $sheetTitle) . "'" : $sheetTitle;
        return $safe . '!' . $a1;
    }

    /**
     * Convert a header label to a column key (slug).
     */
    protected function labelToKey(string $label): string
    {
        $slug = Str::slug($label, '_');
        return $slug ?: 'col_' . substr(md5($label), 0, 6);
    }

    /**
     * Infer column type from header label (optional; default text).
     */
    protected function inferType(string $label): string
    {
        $lower = strtolower($label);
        if (preg_match('/\b(amount|cost|total|q1|q2|q3|q4|price|usd|budget)\b/', $lower)) {
            return 'currency';
        }
        if (preg_match('/\b(quantity|number|%\b|percent)\b/', $lower)) {
            return 'number';
        }
        return 'text';
    }

    /**
     * Import format structure from a Google Spreadsheet URL.
     * Returns column_definition with sheets[] (one entry per tab). Each sheet has columns from row 1.
     */
    public function importFormatFromUrl(string $url): array
    {
        if (! $this->apiKey) {
            throw new \RuntimeException('Google Sheets API key is not configured. Set GOOGLE_SHEETS_API_KEY in .env.');
        }

        $spreadsheetId = self::extractSpreadsheetIdFromUrl($url);
        if (! $spreadsheetId) {
            throw new \RuntimeException('Invalid Google Spreadsheet URL. Use a link like https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/edit');
        }

        $metadata = $this->getSpreadsheetMetadata($spreadsheetId);
        if (empty($metadata)) {
            throw new \RuntimeException('Spreadsheet has no sheets.');
        }

        $sheets = [];
        foreach ($metadata as $index => $info) {
            $title = $info['title'];
            $sheetId = $info['sheet_id'];
            $headers = $this->getSheetHeaderRow($spreadsheetId, $title);
            $columns = [];
            $seen = [];
            foreach ($headers as $i => $label) {
                $label = $label !== '' ? $label : ('Column_' . ($i + 1));
                $key = $this->labelToKey($label);
                if (isset($seen[$key])) {
                    $key = $key . '_' . ($i + 1);
                }
                $seen[$key] = true;
                $columns[] = [
                    'key' => $key,
                    'label' => $label,
                    'type' => $this->inferType($label),
                    'required' => false,
                    'computed' => '',
                ];
            }
            $sheets[] = [
                'key' => (string) $sheetId,
                'name' => $title,
                'columns' => $columns,
            ];
        }

        return [
            'code' => '',
            'name' => '',
            'structure_type' => 'activity_based',
            'line_levels' => ['line'],
            'sheets' => $sheets,
            'required_mappings' => [],
            'google_spreadsheet_id' => $spreadsheetId,
        ];
    }
}
