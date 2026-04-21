<?php

namespace App\Support;

use App\Models\Voucher;

/**
 * Finance approval ladder (L1–L4) for vouchers; budget items use a simplified single gate in the UI.
 */
class ApprovalWorkflow
{
    /**
     * @return array{summary: string, max_levels: int, layers: array<int, array{level: int, code: string, title: string}>}
     */
    public static function definitions(): array
    {
        $roles = config('erp.approval.roles');
        $max = (int) config('erp.approval.levels', 4);

        $layers = [];
        for ($i = 1; $i <= $max; $i++) {
            $layers[] = [
                'level' => $i,
                'code' => 'L'.$i,
                'title' => $roles[$i] ?? ('Level '.$i),
            ];
        }

        return [
            'summary' => 'Vouchers move through finance control layers (L1–L4) based on amount in base currency. '
                .'Smaller amounts may require only L1 or L2; larger amounts add L3 and L4 (General Director).',
            'max_levels' => $max,
            'layers' => $layers,
        ];
    }

    /**
     * @return array{
     *   resource_type: 'voucher',
     *   required_levels: int,
     *   current_approval_level: int,
     *   next_level: int|null,
     *   steps: array<int, array{level: int, code: string, title: string, state: string}>
     * }
     */
    public static function forVoucher(Voucher $v): array
    {
        $roles = config('erp.approval.roles');
        $required = (int) $v->required_approval_level;
        $current = (int) $v->current_approval_level;

        $steps = [];
        for ($i = 1; $i <= $required; $i++) {
            $state = 'upcoming';
            if ($i <= $current) {
                $state = 'completed';
            } elseif ($i === $current + 1) {
                $state = 'current';
            }
            $steps[] = [
                'level' => $i,
                'code' => 'L'.$i,
                'title' => $roles[$i] ?? ('Level '.$i),
                'state' => $state,
            ];
        }

        return [
            'resource_type' => 'voucher',
            'required_levels' => $required,
            'current_approval_level' => $current,
            'next_level' => $required > 0 && $current < $required ? $current + 1 : null,
            'steps' => $steps,
        ];
    }

    /**
     * @return array{
     *   resource_type: 'budget',
     *   summary: string,
     *   required_levels: int,
     *   current_approval_level: int,
     *   next_level: int|null,
     *   steps: array<int, array{level: int, code: string, title: string, state: string}>
     * }
     */
    public static function forBudgetPending(): array
    {
        $roles = config('erp.approval.roles');

        return [
            'resource_type' => 'budget',
            'summary' => 'Budgets use a single finance approval step before they are marked approved.',
            'required_levels' => 1,
            'current_approval_level' => 0,
            'next_level' => 1,
            'steps' => [
                [
                    'level' => 1,
                    'code' => 'L1',
                    'title' => $roles[1] ?? 'Finance Controller',
                    'state' => 'current',
                ],
            ],
        ];
    }
}
