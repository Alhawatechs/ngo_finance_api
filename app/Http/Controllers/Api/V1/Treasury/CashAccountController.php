<?php

namespace App\Http\Controllers\Api\V1\Treasury;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\CashCount;
use App\Models\Organization;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashAccountController extends Controller
{
    /** Notify approvers when a single transfer exceeds this amount (same currency). */
    private const TREASURY_TRANSFER_NOTIFY_THRESHOLD = 10000.0;

    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Display a listing of cash accounts.
     */
    public function index(Request $request)
    {
        $query = CashAccount::where('organization_id', $request->user()->organization_id)
            ->with(['office:id,name,code', 'custodian:id,name', 'glAccount:id,account_code,account_name']);

        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        if ($request->has('currency')) {
            $query->where('currency', $request->currency);
        }

        if ($request->has('cash_type')) {
            $query->where('cash_type', $request->cash_type);
        }

        if ($request->boolean('is_active', true)) {
            $query->active();
        }

        $accounts = $query->orderBy('office_id')->orderBy('name')->get();

        return $this->success($accounts);
    }

    /**
     * Store a newly created cash account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'office_id' => 'required|exists:offices,id',
            'gl_account_id' => 'required|exists:chart_of_accounts,id',
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('cash_accounts')->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                }),
            ],
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'cash_type' => 'required|in:petty_cash,main_cash,safe',
            'limit_amount' => 'nullable|numeric|min:0',
            'custodian_id' => 'nullable|exists:users,id',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['current_balance'] = 0;
        $validated['is_active'] = true;

        $account = CashAccount::create($validated);
        $account->load(['office', 'custodian', 'glAccount']);

        return $this->success($account, 'Cash account created successfully', 201);
    }

    /**
     * Display the specified cash account.
     */
    public function show(Request $request, CashAccount $cashAccount)
    {
        if ($cashAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Cash account not found', 404);
        }

        $cashAccount->load(['office', 'custodian', 'glAccount']);

        // Get recent transactions
        $recentTransactions = $cashAccount->transactions()
            ->with('creator:id,name')
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return $this->success([
            'account' => $cashAccount,
            'recent_transactions' => $recentTransactions,
        ]);
    }

    /**
     * Update the specified cash account.
     */
    public function update(Request $request, CashAccount $cashAccount)
    {
        if ($cashAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Cash account not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'limit_amount' => 'nullable|numeric|min:0',
            'custodian_id' => 'nullable|exists:users,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $cashAccount->update($validated);

        return $this->success($cashAccount, 'Cash account updated successfully');
    }

    /**
     * Remove the specified cash account (soft delete). Only accounts with zero balance and no transactions can be deleted.
     */
    public function destroy(Request $request, CashAccount $cashAccount)
    {
        if ($cashAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Cash account not found', 404);
        }

        if (($cashAccount->current_balance ?? 0) != 0) {
            return $this->error('Cannot delete cash account with non-zero balance.', 422);
        }

        if ($cashAccount->transactions()->exists()) {
            return $this->error('Cannot delete cash account with existing transactions.', 422);
        }

        $cashAccount->delete();

        return $this->success(null, 'Cash account deleted successfully');
    }

    /**
     * Get transactions for a cash account.
     */
    public function transactions(Request $request, CashAccount $cashAccount)
    {
        if ($cashAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Cash account not found', 404);
        }

        $query = $cashAccount->transactions()->with('creator:id,name');

        if ($request->has('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        if ($request->has('from_date')) {
            $query->where('transaction_date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('transaction_date', '<=', $request->to_date);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($transactions);
    }

    /**
     * Record a cash transaction (withdrawal/deposit).
     */
    public function recordTransaction(Request $request, CashAccount $cashAccount)
    {
        if ($cashAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Cash account not found', 404);
        }

        $validated = $request->validate([
            'transaction_type' => 'required|in:withdrawal,deposit,adjustment',
            'transaction_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string',
            'payee_payer' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
        ]);

        // Check if withdrawal is possible
        if ($validated['transaction_type'] === 'withdrawal' && !$cashAccount->canWithdraw($validated['amount'])) {
            return $this->error('Insufficient balance for withdrawal', 400);
        }

        DB::beginTransaction();
        try {
            // Generate transaction number
            $transactionNumber = $this->generateTransactionNumber($cashAccount, $validated['transaction_type']);

            // Calculate new balance
            $isCredit = in_array($validated['transaction_type'], ['deposit']);
            $newBalance = $isCredit 
                ? $cashAccount->current_balance + $validated['amount']
                : $cashAccount->current_balance - $validated['amount'];

            // Create transaction
            $transaction = CashTransaction::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_number' => $transactionNumber,
                'transaction_date' => $validated['transaction_date'],
                'transaction_type' => $validated['transaction_type'],
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'currency' => $cashAccount->currency,
                'exchange_rate' => 1,
                'running_balance' => $newBalance,
                'payee_payer' => $validated['payee_payer'],
                'reference' => $validated['reference'],
                'status' => 'completed',
                'created_by' => $request->user()->id,
            ]);

            // Update cash account balance
            $cashAccount->current_balance = $newBalance;
            $cashAccount->save();

            DB::commit();

            $transaction->load('creator');

            return $this->success($transaction, 'Transaction recorded successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to record transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Transfer cash between accounts.
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'from_account_id' => 'required|exists:cash_accounts,id',
            'to_account_id' => 'required|exists:cash_accounts,id|different:from_account_id',
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'description' => 'required|string',
        ]);

        $fromAccount = CashAccount::where('organization_id', $request->user()->organization_id)
            ->findOrFail($validated['from_account_id']);
        $toAccount = CashAccount::where('organization_id', $request->user()->organization_id)
            ->findOrFail($validated['to_account_id']);

        // Validate same currency
        if ($fromAccount->currency !== $toAccount->currency) {
            return $this->error('Cannot transfer between different currencies. Use exchange instead.', 400);
        }

        // Check balance
        if (!$fromAccount->canWithdraw($validated['amount'])) {
            return $this->error('Insufficient balance in source account', 400);
        }

        DB::beginTransaction();
        try {
            // Create outgoing transaction
            $outTransaction = CashTransaction::create([
                'cash_account_id' => $fromAccount->id,
                'transaction_number' => $this->generateTransactionNumber($fromAccount, 'transfer_out'),
                'transaction_date' => $validated['transaction_date'],
                'transaction_type' => 'transfer_out',
                'description' => $validated['description'] . ' (Transfer to ' . $toAccount->name . ')',
                'amount' => $validated['amount'],
                'currency' => $fromAccount->currency,
                'exchange_rate' => 1,
                'running_balance' => $fromAccount->current_balance - $validated['amount'],
                'status' => 'completed',
                'created_by' => $request->user()->id,
            ]);

            // Create incoming transaction
            $inTransaction = CashTransaction::create([
                'cash_account_id' => $toAccount->id,
                'transaction_number' => $this->generateTransactionNumber($toAccount, 'transfer_in'),
                'transaction_date' => $validated['transaction_date'],
                'transaction_type' => 'transfer_in',
                'description' => $validated['description'] . ' (Transfer from ' . $fromAccount->name . ')',
                'amount' => $validated['amount'],
                'currency' => $toAccount->currency,
                'exchange_rate' => 1,
                'running_balance' => $toAccount->current_balance + $validated['amount'],
                'related_transaction_id' => $outTransaction->id,
                'status' => 'completed',
                'created_by' => $request->user()->id,
            ]);

            // Link transactions
            $outTransaction->update(['related_transaction_id' => $inTransaction->id]);

            // Update balances
            $fromAccount->current_balance -= $validated['amount'];
            $fromAccount->save();

            $toAccount->current_balance += $validated['amount'];
            $toAccount->save();

            DB::commit();

            $this->notifyLargeCashTransferIfNeeded(
                $request,
                $fromAccount,
                $toAccount,
                (float) $validated['amount'],
                $outTransaction->id
            );

            return $this->success([
                'out_transaction' => $outTransaction,
                'in_transaction' => $inTransaction,
            ], 'Transfer completed successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to transfer: ' . $e->getMessage(), 500);
        }
    }

    private function notifyLargeCashTransferIfNeeded(
        Request $request,
        CashAccount $fromAccount,
        CashAccount $toAccount,
        float $amount,
        int $outTransactionId
    ): void {
        if ($amount < self::TREASURY_TRANSFER_NOTIFY_THRESHOLD) {
            return;
        }
        $orgId = (int) $fromAccount->organization_id;
        $ids = $this->notificationService->approverUserIdsForLevel($orgId, 1);
        $ids = array_values(array_diff($ids, [$request->user()->id]));
        if ($ids === []) {
            return;
        }
        $this->notificationService->notifyUsers(
            $ids,
            'treasury',
            'Large cash transfer',
            sprintf(
                '%s %s moved from %s to %s.',
                number_format($amount, 2),
                $fromAccount->currency,
                $fromAccount->name,
                $toAccount->name
            ),
            '/treasury/cash',
            ['cash_transaction_id' => $outTransactionId]
        );
    }

    /**
     * Exchange cash between two accounts in different currencies.
     * Debits amount_from in from_account currency; credits amount_to in to_account currency.
     * exchange_rate = units of to-currency per 1 unit of from-currency (e.g. AFN per 1 USD).
     */
    public function exchange(Request $request)
    {
        $validated = $request->validate([
            'from_account_id' => 'required|exists:cash_accounts,id',
            'to_account_id' => 'required|exists:cash_accounts,id|different:from_account_id',
            'transaction_date' => 'required|date',
            'amount_from' => 'required|numeric|min:0.01',
            'exchange_rate' => 'required|numeric|min:0.00000001',
            'description' => 'required|string',
            'reference' => 'nullable|string|max:255',
        ]);

        $fromAccount = CashAccount::where('organization_id', $request->user()->organization_id)
            ->findOrFail($validated['from_account_id']);
        $toAccount = CashAccount::where('organization_id', $request->user()->organization_id)
            ->findOrFail($validated['to_account_id']);

        if ($fromAccount->currency === $toAccount->currency) {
            return $this->error('Cannot exchange between same currency. Use transfer instead.', 400);
        }

        $allowed = Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id);
        if (! in_array($fromAccount->currency, $allowed, true) || ! in_array($toAccount->currency, $allowed, true)) {
            return $this->error('One or both currencies are not active for this organization.', 422);
        }

        $amountFrom = round((float) $validated['amount_from'], 2);
        $amountTo = round($amountFrom * (float) $validated['exchange_rate'], 2);

        if ($amountTo < 0.01) {
            return $this->error('Converted amount is too small.', 400);
        }

        if (! $fromAccount->canWithdraw($amountFrom)) {
            return $this->error('Insufficient balance in source account', 400);
        }

        DB::beginTransaction();
        try {
            $fromAccount->refresh();
            $toAccount->refresh();

            $outTx = CashTransaction::create([
                'cash_account_id' => $fromAccount->id,
                'transaction_number' => $this->generateTransactionNumber($fromAccount, 'exchange'),
                'transaction_date' => $validated['transaction_date'],
                'transaction_type' => 'exchange',
                'description' => $validated['description'] . ' (Exchange out → ' . $toAccount->name . ')',
                'amount' => $amountFrom,
                'currency' => $fromAccount->currency,
                'exchange_rate' => (float) $validated['exchange_rate'],
                'running_balance' => $fromAccount->current_balance - $amountFrom,
                'reference' => $validated['reference'] ?? null,
                'status' => 'completed',
                'created_by' => $request->user()->id,
            ]);

            $inTx = CashTransaction::create([
                'cash_account_id' => $toAccount->id,
                'transaction_number' => $this->generateTransactionNumber($toAccount, 'exchange'),
                'transaction_date' => $validated['transaction_date'],
                'transaction_type' => 'exchange',
                'description' => $validated['description'] . ' (Exchange in ← ' . $fromAccount->name . ')',
                'amount' => $amountTo,
                'currency' => $toAccount->currency,
                'exchange_rate' => (float) $validated['exchange_rate'],
                'running_balance' => $toAccount->current_balance + $amountTo,
                'reference' => $validated['reference'] ?? null,
                'related_transaction_id' => $outTx->id,
                'status' => 'completed',
                'created_by' => $request->user()->id,
            ]);

            $outTx->update(['related_transaction_id' => $inTx->id]);

            $fromAccount->current_balance -= $amountFrom;
            $fromAccount->save();

            $toAccount->current_balance += $amountTo;
            $toAccount->save();

            DB::commit();

            return $this->success([
                'out_transaction' => $outTx->fresh('creator'),
                'in_transaction' => $inTx->fresh('creator'),
            ], 'Exchange completed successfully');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('Failed to exchange: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Record cash count.
     */
    public function recordCashCount(Request $request, CashAccount $cashAccount)
    {
        if ($cashAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Cash account not found', 404);
        }

        $validated = $request->validate([
            'count_date' => 'required|date',
            'actual_balance' => 'required|numeric|min:0',
            'denomination_details' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $cashCount = CashCount::create([
            'cash_account_id' => $cashAccount->id,
            'count_date' => $validated['count_date'],
            'expected_balance' => $cashAccount->current_balance,
            'actual_balance' => $validated['actual_balance'],
            'difference' => $validated['actual_balance'] - $cashAccount->current_balance,
            'denomination_details' => $validated['denomination_details'],
            'notes' => $validated['notes'],
            'counted_by' => $request->user()->id,
        ]);

        return $this->success($cashCount, 'Cash count recorded successfully', 201);
    }

    /**
     * Get cash count history.
     */
    public function cashCountHistory(Request $request, CashAccount $cashAccount)
    {
        if ($cashAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Cash account not found', 404);
        }

        $counts = $cashAccount->cashCounts()
            ->with(['counter:id,name', 'verifier:id,name'])
            ->orderBy('count_date', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($counts);
    }

    /**
     * Verify cash count.
     */
    public function verifyCashCount(Request $request, CashCount $cashCount)
    {
        $cashAccount = $cashCount->cashAccount;

        if ($cashAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Cash count not found', 404);
        }

        if ($cashCount->isVerified()) {
            return $this->error('Cash count already verified', 400);
        }

        $cashCount->update([
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        return $this->success($cashCount, 'Cash count verified successfully');
    }

    /**
     * Get summary for all cash accounts.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $accounts = CashAccount::where('organization_id', $orgId)
            ->with('office:id,name,code')
            ->active()
            ->get();

        $summary = $accounts->groupBy('currency')->map(function ($group, $currency) {
            return [
                'currency' => $currency,
                'total_balance' => $group->sum('current_balance'),
                'accounts_count' => $group->count(),
                'by_type' => $group->groupBy('cash_type')->map(fn($t) => $t->sum('current_balance')),
            ];
        });

        $totalUSD = CashAccount::where('organization_id', $orgId)
            ->active()
            ->where('currency', 'USD')
            ->sum('current_balance');

        return $this->success([
            'by_currency' => $summary,
            'total_usd' => $totalUSD,
            'accounts' => $accounts,
        ]);
    }

    /**
     * Generate transaction number.
     */
    private function generateTransactionNumber(CashAccount $account, string $type): string
    {
        $prefix = match($type) {
            'withdrawal' => 'CW',
            'deposit' => 'CD',
            'transfer_in' => 'CTI',
            'transfer_out' => 'CTO',
            'exchange' => 'CX',
            default => 'CT',
        };

        $date = now()->format('Ymd');
        $count = CashTransaction::where('cash_account_id', $account->id)
            ->whereDate('created_at', today())
            ->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, $date, $count);
    }
}
