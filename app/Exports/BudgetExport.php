<?php

namespace App\Exports;

use App\Models\Budget;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BudgetExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function __construct(
        private Budget $budget,
        private string $format = 'unfpa_who'
    ) {
    }

    public function array(): array
    {
        $lines = $this->budget->lines()->with('account')->get();
        $rows = [];

        foreach ($lines as $line) {
            $attrs = $line->format_attributes ?? [];
            if ($this->format === 'unicef_her') {
                $rows[] = [
                    $attrs['section_code'] ?? '',
                    $attrs['item_description'] ?? $line->description,
                    $line->account?->account_code ?? '',
                    $line->account?->account_name ?? '',
                    $attrs['cso_contribution'] ?? 0,
                    $attrs['unicef_contribution'] ?? 0,
                    $line->q1_amount ?? 0,
                    $line->q2_amount ?? 0,
                    $line->q3_amount ?? 0,
                    $line->q4_amount ?? 0,
                    $attrs['remark'] ?? '',
                ];
            } else {
                $rows[] = [
                    $attrs['category_code'] ?? '',
                    $attrs['budget_line_description'] ?? $line->description,
                    $line->account?->account_code ?? '',
                    $attrs['quantity'] ?? 0,
                    $attrs['unit_cost'] ?? 0,
                    $attrs['duration_recurrence'] ?? '',
                    $attrs['cost_pct'] ?? 100,
                    $line->annual_amount ?? 0,
                    $attrs['remarks'] ?? '',
                ];
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        if ($this->format === 'unicef_her') {
            return ['Section', 'Item Description', 'Account Code', 'Account Name', 'CSO (USD)', 'UNICEF (USD)', 'Q1', 'Q2', 'Q3', 'Q4', 'Remark'];
        }
        return ['Code', 'Budget Line Description', 'Account Code', 'Qty', 'Unit Cost', 'Duration/Recurrence', '% Cost', 'Total Cost', 'Remarks'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'alignment' => ['horizontal' => 'left']],
        ];
    }
}
