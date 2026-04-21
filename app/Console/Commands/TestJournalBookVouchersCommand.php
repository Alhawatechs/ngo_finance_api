<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\Finance\VoucherController;
use App\Models\ChartOfAccount;
use App\Models\Fund;
use App\Models\Journal;
use App\Models\Office;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\OfficeContext;
use App\Services\OfficeScopeService;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Smoke-test: create N vouchers linked to a journal book (same payload shape as the API).
 * Run: php artisan journal:test-vouchers --count=10
 */
class TestJournalBookVouchersCommand extends Command
{
    protected $signature = 'journal:test-vouchers
                            {--journal= : Journal book id (optional; uses first active book for your org)}
                            {--user= : User id to authenticate as (optional; first org user)}
                            {--count=10 : Number of vouchers to create}';

    protected $description = 'Create test vouchers linked to a journal book (validates voucher + journal_id flow)';

    public function handle(): int
    {
        $count = max(1, min(100, (int) $this->option('count')));
        $journalId = $this->option('journal') ? (int) $this->option('journal') : null;
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $user = $userId
            ? User::find($userId)
            : User::whereNotNull('organization_id')->orderBy('id')->first();

        if (! $user || ! $user->organization_id) {
            $this->error('No user with organization_id found. Seed the database or pass --user=');

            return self::FAILURE;
        }

        $organization = Organization::find($user->organization_id);
        if (! $organization) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }

        $office = Office::where('organization_id', $user->organization_id)->orderByDesc('is_head_office')->first();
        if (! $office) {
            $this->error('No office found for this organization.');

            return self::FAILURE;
        }

        $scope = app(OfficeScopeService::class);
        if (! $scope->canViewAllJournalBooks($user) && ! $user->office_id) {
            $this->error('Your user needs an office assignment, or use a user with view-all journal books (e.g. finance director).');

            return self::FAILURE;
        }

        $base = Journal::query()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at');
        if (! $scope->canViewAllJournalBooks($user) && $user->office_id) {
            $base->where('office_id', $user->office_id);
        }

        $journal = $journalId
            ? (clone $base)->where('id', $journalId)->first()
            : (clone $base)->orderBy('id')->first();

        if (! $journal) {
            $this->error('No accessible journal book found. Create one for this office in GL → Journal entries or pass --journal=<id>.');

            return self::FAILURE;
        }

        $this->info("User: {$user->email} (id {$user->id})");
        $this->info("Office: {$office->name} (id {$office->id})");
        $this->info("Journal book: {$journal->name} (id {$journal->id}, code {$journal->code})");

        $stats = ['ok' => 0, 'failed' => 0];
        $abortReason = null;

        OfficeContext::runWithOffice($office, function () use ($user, $organization, $journal, $office, $count, &$stats, &$abortReason) {
            $project = $journal->project_id
                ? Project::where('organization_id', $user->organization_id)->where('id', $journal->project_id)->first()
                : Project::where('organization_id', $user->organization_id)->orderBy('id')->first();

            $fund = Fund::where('organization_id', $user->organization_id)->where('is_active', true)->orderBy('code')->first();

            $accounts = ChartOfAccount::query()
                ->where('organization_id', $user->organization_id)
                ->where('is_posting', true)
                ->where('is_active', true)
                ->orderBy('id')
                ->take(2)
                ->get();

            if ($accounts->count() < 2) {
                $this->error('Need at least 2 active posting accounts in chart of accounts.');
                $abortReason = 'accounts';

                return;
            }

            if (($organization->project_mandatory ?? true) && ! $project) {
                $this->error('Organization requires a project; link the journal book to a project or create a project.');
                $abortReason = 'project';

                return;
            }

            if (($organization->fund_mandatory ?? true) && ! $fund) {
                $suffix = substr(str_replace('.', '', (string) microtime(true)), -6);
                $fund = Fund::create([
                    'organization_id' => $user->organization_id,
                    'code' => 'TST'.$suffix,
                    'name' => 'CLI journal test fund',
                    'fund_type' => 'unrestricted',
                    'initial_amount' => 0,
                    'is_active' => true,
                ]);
                $this->warn('Created temporary fund '.$fund->code.' (no active fund existed).');
            }

            $province = $journal->province_code ?: '01';
            if (strlen((string) $province) !== 2) {
                $province = '01';
            }
            $locationCode = $journal->getRawOriginal('location_code') ?? null;
            if ($locationCode === null || $locationCode === '') {
                $locationCode = '1';
            }

            $voucherOfficeId = $journal->office_id ? (int) $journal->office_id : (int) $office->id;

            $expenseId = (int) $accounts[0]->id;
            $creditId = (int) $accounts[1]->id;

            Auth::login($user);

            $controller = app(VoucherController::class);

            for ($i = 1; $i <= $count; $i++) {
                $amount = 10 + $i;
                $payload = [
                    'office_id' => $voucherOfficeId,
                    'project_id' => $project?->id,
                    'province_code' => $province,
                    'location_code' => (string) $locationCode,
                    'fund_id' => $fund?->id,
                    'voucher_type' => 'payment',
                    'voucher_date' => now()->format('Y-m-d'),
                    'payee_name' => 'CLI test payee',
                    'description' => "Journal book test voucher #{$i} (journal {$journal->id})",
                    'currency' => $organization->default_currency ?? 'USD',
                    'exchange_rate' => 1,
                    'payment_method' => 'cash',
                    'journal_id' => $journal->id,
                    'lines' => [
                        [
                            'account_id' => $expenseId,
                            'debit_amount' => $amount,
                            'credit_amount' => 0,
                            'description' => 'Expense line',
                        ],
                        [
                            'account_id' => $creditId,
                            'debit_amount' => 0,
                            'credit_amount' => $amount,
                            'description' => 'Credit line',
                        ],
                    ],
                ];

                if (! $project) {
                    unset($payload['project_id'], $payload['province_code'], $payload['location_code']);
                }

                if ($organization->cost_center_mandatory ?? false) {
                    $payload['lines'][0]['cost_center'] = 'TEST-CC';
                    $payload['lines'][1]['cost_center'] = 'TEST-CC';
                }

                $request = Request::create('/api/v1/vouchers', 'POST', $payload);
                $request->setUserResolver(fn () => $user);

                try {
                    try {
                        $response = $controller->store($request);
                    } catch (HttpResponseException $e) {
                        $response = $e->getResponse();
                    }
                    $status = $response->getStatusCode();
                    $data = json_decode($response->getContent(), true);
                    if ($status >= 200 && $status < 300 && ($data['success'] ?? false)) {
                        $stats['ok']++;
                        $vn = $data['data']['voucher_number'] ?? '?';
                        $vid = $data['data']['id'] ?? '?';
                        $this->line("  [{$i}/{$count}] OK voucher #{$vid} number {$vn}");
                    } else {
                        $stats['failed']++;
                        $msg = $data['message'] ?? $response->getContent();
                        $this->warn("  [{$i}/{$count}] HTTP {$status}: {$msg}");
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $this->warn("  [{$i}/{$count}] EX: {$e->getMessage()}");
                }
            }
        });

        if ($abortReason !== null) {
            return self::FAILURE;
        }

        $ok = $stats['ok'];
        $failed = $stats['failed'];

        $this->newLine();
        if ($failed === 0 && $ok === $count) {
            $this->info("Done: {$ok} voucher(s) created successfully for journal book {$journal->id}.");

            return self::SUCCESS;
        }

        $this->warn("Finished with {$ok} OK, {$failed} failed (expected {$count}).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
