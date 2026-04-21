<?php

namespace App\Http\Controllers\Api\V1\Treasury;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\BankReconciliation;
use App\Models\Organization;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BankAccountController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Display a listing of bank accounts.
     */
    public function index(Request $request)
    {
        $query = BankAccount::where('organization_id', $request->user()->organization_id)
            ->with(['office:id,name,code', 'glAccount:id,account_code,account_name']);

        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        if ($request->has('currency')) {
            $query->where('currency', $request->currency);
        }

        if ($request->has('account_type')) {
            $query->where('account_type', $request->account_type);
        }

        if ($request->boolean('is_active', true)) {
            $query->active();
        }

        $accounts = $query->orderBy('bank_name')->orderBy('account_name')->get();

        return $this->success($accounts);
    }

    /**
     * Store a newly created bank account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'office_id' => 'required|exists:offices,id',
            'gl_account_id' => 'required|exists:chart_of_accounts,id',
            'bank_name' => 'required|string|max:255',
            'branch_name' => 'nullable|string|max:255',
            'account_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('bank_accounts')->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                }),
            ],
            'account_name' => 'required|string|max:255',
            'account_type' => 'required|in:checking,savings,fixed_deposit,money_market',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'swift_code' => 'nullable|string|max:20',
            'iban' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['current_balance'] = $validated['opening_balance'] ?? 0;
        $validated['available_balance'] = $validated['opening_balance'] ?? 0;
        $validated['is_active'] = true;

        unset($validated['opening_balance']);

        $account = BankAccount::create($validated);
        $account->load(['office', 'glAccount']);

        return $this->success($account, 'Bank account created successfully', 201);
    }

    /**
     * Display the specified bank account.
     */
    public function show(Request $request, BankAccount $bankAccount)
    {
        if ($bankAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Bank account not found', 404);
        }

        $bankAccount->load(['office', 'glAccount']);

        // Get recent transactions
        $recentTransactions = $bankAccount->transactions()
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get reconciliation status
        $unreconciledCount = $bankAccount->transactions()->unreconciled()->count();
        $lastReconciliation = $bankAccount->reconciliations()
            ->orderBy('reconciliation_date', 'desc')
            ->first();

        return $this->success([
            'account' => $bankAccount,
            'recent_transactions' => $recentTransactions,
            'unreconciled_count' => $unreconciledCount,
            'last_reconciliation' => $lastReconciliation,
        ]);
    }

    /**
     * Update the specified bank account.
     */
    public function update(Request $request, BankAccount $bankAccount)
    {
        if ($bankAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Bank account not found', 404);
        }

        $validated = $request->validate([
            'bank_name' => 'sometimes|string|max:255',
            'branch_name' => 'nullable|string|max:255',
            'account_name' => 'sometimes|string|max:255',
            'swift_code' => 'nullable|string|max:20',
            'iban' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);

        $bankAccount->update($validated);

        return $this->success($bankAccount, 'Bank account updated successfully');
    }

    /**
     * Remove the specified bank account (soft delete). Only accounts with zero balance and no transactions can be deleted.
     */
    public function destroy(Request $request, BankAccount $bankAccount)
    {
        if ($bankAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Bank account not found', 404);
        }

        if (($bankAccount->current_balance ?? 0) != 0) {
            return $this->error('Cannot delete bank account with non-zero balance.', 422);
        }

        if ($bankAccount->transactions()->exists()) {
            return $this->error('Cannot delete bank account with existing transactions.', 422);
        }

        $bankAccount->delete();

        return $this->success(null, 'Bank account deleted successfully');
    }

    /**
     * Get transactions for a bank account.
     */
    public function transactions(Request $request, BankAccount $bankAccount)
    {
        if ($bankAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Bank account not found', 404);
        }

        $query = $bankAccount->transactions();

        if ($request->has('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        if ($request->has('from_date')) {
            $query->where('transaction_date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('transaction_date', '<=', $request->to_date);
        }

        if ($request->has('is_reconciled')) {
            $query->where('is_reconciled', $request->boolean('is_reconciled'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($transactions);
    }

    /**
     * Record a bank transaction.
     */
    public function recordTransaction(Request $request, BankAccount $bankAccount)
    {
        if ($bankAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Bank account not found', 404);
        }

        $validated = $request->validate([
            'transaction_type' => 'required|in:deposit,withdrawal,transfer_in,transfer_out,fee,interest,check',
            'transaction_date' => 'required|date',
            'value_date' => 'nullable|date',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string',
            'reference' => 'nullable|string|max:255',
            'check_number' => 'nullable|string|max:50',
            'payee_payer' => 'nullable|string|max:255',
        ]);

        $isDebit = in_array($validated['transaction_type'], ['withdrawal', 'transfer_out', 'fee', 'check']);

        DB::beginTransaction();
        try {
            // Calculate new balance
            $newBalance = $isDebit
                ? $bankAccount->current_balance - $validated['amount']
                : $bankAccount->current_balance + $validated['amount'];

            // Create transaction
            $transaction = BankTransaction::create([
                'bank_account_id' => $bankAccount->id,
                'transaction_date' => $validated['transaction_date'],
                'value_date' => $validated['value_date'] ?? $validated['transaction_date'],
                'transaction_type' => $validated['transaction_type'],
                'reference' => $validated['reference'],
                'description' => $validated['description'],
                'debit_amount' => $isDebit ? $validated['amount'] : 0,
                'credit_amount' => $isDebit ? 0 : $validated['amount'],
                'running_balance' => $newBalance,
                'check_number' => $validated['check_number'],
                'payee_payer' => $validated['payee_payer'],
                'is_reconciled' => false,
                'status' => 'cleared',
            ]);

            // Update bank account balance
            $bankAccount->current_balance = $newBalance;
            $bankAccount->available_balance = $newBalance;
            $bankAccount->save();

            DB::commit();

            return $this->success($transaction, 'Transaction recorded successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to record transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Start a new bank reconciliation.
     */
    public function startReconciliation(Request $request, BankAccount $bankAccount)
    {
        if ($bankAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Bank account not found', 404);
        }

        $validated = $request->validate([
            'reconciliation_date' => 'required|date',
            'statement_balance' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        // Check if there's an in-progress reconciliation
        $existingRecon = $bankAccount->reconciliations()
            ->where('status', 'in_progress')
            ->first();

        if ($existingRecon) {
            return $this->error('There is already an in-progress reconciliation', 400);
        }

        $reconciliation = BankReconciliation::create([
            'bank_account_id' => $bankAccount->id,
            'reconciliation_date' => $validated['reconciliation_date'],
            'statement_balance' => $validated['statement_balance'],
            'book_balance' => $bankAccount->current_balance,
            'adjusted_book_balance' => $bankAccount->current_balance,
            'difference' => $validated['statement_balance'] - $bankAccount->current_balance,
            'status' => 'in_progress',
            'notes' => $validated['notes'],
            'prepared_by' => $request->user()->id,
        ]);

        // Get unreconciled transactions
        $unreconciledTransactions = $bankAccount->transactions()
            ->unreconciled()
            ->orderBy('transaction_date')
            ->get();

        $this->notifyApproversBankReconciliationStarted($bankAccount, $reconciliation, $request->user());

        return $this->success([
            'reconciliation' => $reconciliation,
            'unreconciled_transactions' => $unreconciledTransactions,
        ], 'Reconciliation started');
    }

    /**
     * Inform finance approvers that a bank reconciliation session was started (visibility / controls).
     */
    private function notifyApproversBankReconciliationStarted(
        BankAccount $bankAccount,
        BankReconciliation $reconciliation,
        User $actor
    ): void {
        $orgId = (int) $bankAccount->organization_id;
        $ids = $this->notificationService->approverUserIdsForLevel($orgId, 1);
        $ids = array_values(array_diff($ids, [$actor->id]));
        if ($ids === []) {
            return;
        }
        $label = trim($bankAccount->bank_name.' · '.$bankAccount->account_name);
        $dateLabel = $reconciliation->reconciliation_date
            ? \Carbon\Carbon::parse($reconciliation->reconciliation_date)->format('Y-m-d')
            : '';
        $this->notificationService->notifyUsers(
            $ids,
            'treasury',
            'Bank reconciliation started',
            "{$label}: reconciliation session for {$dateLabel} is in progress.",
            '/treasury/bank/reconciliation',
            [
                'bank_reconciliation_id' => $reconciliation->id,
                'bank_account_id' => $bankAccount->id,
            ]
        );
    }

    /**
     * Mark transactions as reconciled.
     */
    public function reconcileTransactions(Request $request, BankReconciliation $reconciliation)
    {
        $bankAccount = $reconciliation->bankAccount;

        if ($bankAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Reconciliation not found', 404);
        }

        if ($reconciliation->status !== 'in_progress') {
            return $this->error('Reconciliation is not in progress', 400);
        }

        $validated = $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'exists:bank_transactions,id',
        ]);

        BankTransaction::whereIn('id', $validated['transaction_ids'])
            ->where('bank_account_id', $bankAccount->id)
            ->update([
                'is_reconciled' => true,
                'reconciliation_id' => $reconciliation->id,
            ]);

        // Recalculate difference
        $reconciledAmount = BankTransaction::where('reconciliation_id', $reconciliation->id)
            ->selectRaw('SUM(credit_amount - debit_amount) as total')
            ->first()
            ->total ?? 0;

        $reconciliation->adjusted_book_balance = $reconciliation->book_balance + $reconciledAmount;
        $reconciliation->difference = $reconciliation->statement_balance - $reconciliation->adjusted_book_balance;
        $reconciliation->save();

        return $this->success($reconciliation, 'Transactions reconciled');
    }

    /**
     * Complete a reconciliation.
     */
    public function completeReconciliation(Request $request, BankReconciliation $reconciliation)
    {
        $bankAccount = $reconciliation->bankAccount;

        if ($bankAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Reconciliation not found', 404);
        }

        if ($reconciliation->status !== 'in_progress') {
            return $this->error('Reconciliation is not in progress', 400);
        }

        if (abs($reconciliation->difference) > 0.01) {
            return $this->error('Reconciliation has an unresolved difference of ' . $reconciliation->difference, 400);
        }

        $reconciliation->update([
            'status' => 'completed',
        ]);

        // Update bank account
        $bankAccount->update([
            'last_reconciled_date' => $reconciliation->reconciliation_date,
            'last_reconciled_balance' => $reconciliation->statement_balance,
        ]);

        return $this->success($reconciliation, 'Reconciliation completed');
    }

    /**
     * Get summary of all bank accounts.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $accounts = BankAccount::where('organization_id', $orgId)
            ->with('office:id,name,code')
            ->active()
            ->get();

        $summary = $accounts->groupBy('currency')->map(function ($group, $currency) {
            return [
                'currency' => $currency,
                'total_balance' => $group->sum('current_balance'),
                'accounts_count' => $group->count(),
                'by_type' => $group->groupBy('account_type')->map(fn($t) => $t->sum('current_balance')),
            ];
        });

        return $this->success([
            'by_currency' => $summary,
            'accounts' => $accounts,
        ]);
    }
}
