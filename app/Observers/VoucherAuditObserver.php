<?php

namespace App\Observers;

use App\Models\Voucher;
use App\Services\AuditLogService;

class VoucherAuditObserver
{
    public function created(Voucher $voucher): void
    {
        $this->log('create', $voucher, 'Voucher created', null, $voucher->only([
            'voucher_number', 'voucher_type', 'voucher_date', 'payee_name', 'total_amount', 'currency', 'status',
        ]));
    }

    public function updated(Voucher $voucher): void
    {
        $changes = $voucher->getChanges();
        unset($changes['updated_at']);
        $relevant = array_intersect_key($changes, array_flip([
            'voucher_number', 'voucher_type', 'voucher_date', 'payee_name', 'total_amount', 'currency', 'status',
            'current_approval_level', 'rejection_reason',
        ]));
        if (empty($relevant)) {
            return;
        }
        $old = $voucher->getOriginal();
        $oldValues = array_intersect_key($old, $relevant);
        AuditLogService::log('update', $voucher, 'Voucher updated', $oldValues, $relevant);
    }

    public function deleted(Voucher $voucher): void
    {
        AuditLogService::log('delete', $voucher, 'Voucher deleted', $voucher->only([
            'voucher_number', 'voucher_type', 'total_amount', 'status',
        ]), null);
    }

    private function log(string $action, Voucher $voucher, string $description, ?array $oldValues, ?array $newValues): void
    {
        AuditLogService::log($action, $voucher, $description, $oldValues, $newValues);
    }
}
