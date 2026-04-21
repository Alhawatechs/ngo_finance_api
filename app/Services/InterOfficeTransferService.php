<?php

namespace App\Services;

use App\Models\InterOfficeTransfer;
use App\Models\Office;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * When offices use separate DBs: post transfer entries in each office's database.
 * Central inter_office_transfers table holds metadata; sent_voucher_id/received_voucher_id
 * store voucher IDs in the from/to office DBs (no cross-DB FK).
 */
class InterOfficeTransferService
{
    public function __construct(
        protected OfficeContext $officeContext
    ) {}

    /**
     * Post vouchers in from_office and to_office DBs for the transfer.
     * Call this when creating or completing an inter-office transfer.
     */
    public function postTransferEntries(InterOfficeTransfer $transfer): void
    {
        $fromOffice = $transfer->fromOffice;
        $toOffice = $transfer->toOffice;
        if (!$fromOffice->database_connection || !$toOffice->database_connection) {
            return; // single-DB or unprovisioned offices
        }

        OfficeContext::runWithOffice($fromOffice, function () use ($transfer) {
            $sentVoucher = $this->createTransferVoucherInOffice($transfer, 'out', $transfer->from_office_id);
            $transfer->update(['sent_voucher_id' => $sentVoucher->id]);
        });

        OfficeContext::runWithOffice($toOffice, function () use ($transfer) {
            $receivedVoucher = $this->createTransferVoucherInOffice($transfer, 'in', $transfer->to_office_id);
            $transfer->update(['received_voucher_id' => $receivedVoucher->id]);
        });
    }

    /**
     * Create a voucher in the current office DB for the transfer (in or out).
     */
    protected function createTransferVoucherInOffice(InterOfficeTransfer $transfer, string $direction, int $officeId): Voucher
    {
        $number = $transfer->transfer_number . '-' . $direction;
        return Voucher::create([
            'organization_id' => $transfer->organization_id,
            'office_id' => $officeId,
            'voucher_number' => $number,
            'voucher_type' => 'journal',
            'voucher_date' => $transfer->transfer_date,
            'description' => $transfer->description . ' (Inter-office ' . $direction . ')',
            'currency' => $transfer->currency,
            'total_amount' => $transfer->amount,
            'base_currency_amount' => $transfer->amount,
            'status' => $direction === 'out' ? 'posted' : 'draft',
            'created_by' => $transfer->created_by,
        ]);
    }
}
