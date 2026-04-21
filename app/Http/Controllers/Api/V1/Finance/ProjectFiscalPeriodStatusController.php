<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\Project;
use App\Models\ProjectFiscalPeriodStatus;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ProjectFiscalPeriodStatusController extends Controller
{
    /**
     * List fiscal periods for a year with per-project close status (NGO project overlay).
     */
    public function index(Request $request, Project $project, FiscalYear $fiscal_year)
    {
        $denied = $this->authorizeView($request);
        if ($denied !== null) {
            return $denied;
        }

        if ((int) $project->organization_id !== (int) $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }
        if ((int) $fiscal_year->organization_id !== (int) $request->user()->organization_id) {
            return $this->error('Fiscal year not found', 404);
        }

        $periods = $fiscal_year->periods()->orderBy('period_number')->get();
        $statusRows = ProjectFiscalPeriodStatus::query()
            ->where('project_id', $project->id)
            ->whereIn('fiscal_period_id', $periods->pluck('id'))
            ->get()
            ->keyBy('fiscal_period_id');

        $project->loadMissing('organization');
        $org = $project->organization ?? $request->user()->organization;
        $orgBaseCurrency = strtoupper((string) ($org?->default_currency ?? 'USD'));
        $journalBookCurrency = $this->resolveJournalBookCurrency($project);
        $displayCurrency = $journalBookCurrency ?? $orgBaseCurrency;
        $totalsUseOrganizationBase = $journalBookCurrency === null || $journalBookCurrency === $orgBaseCurrency;

        $voucherStatsByPeriodId = $this->voucherStatsForProjectPeriods(
            $project,
            $periods,
            $orgBaseCurrency,
            $displayCurrency,
            $totalsUseOrganizationBase
        );

        $data = $periods->map(function (FiscalPeriod $fp) use ($statusRows, $voucherStatsByPeriodId) {
            $row = $statusRows->get($fp->id);
            $stats = $voucherStatsByPeriodId[$fp->id] ?? [
                'voucher_number_from' => null,
                'voucher_number_to' => null,
                'total_base_amount' => '0',
                'posted_voucher_count' => 0,
            ];

            $projectCloseState = 'opened';
            if ($row) {
                $projectCloseState = $row->status === 'locked' ? 'permanently_locked' : 'temporarily_locked';
            }

            return [
                'fiscal_period' => $fp,
                'project_period_status' => $row ? $row->status : null,
                'project_close_state' => $projectCloseState,
                'project_closed_at' => $row?->closed_at,
                'voucher_number_from' => $stats['voucher_number_from'],
                'voucher_number_to' => $stats['voucher_number_to'],
                'total_base_amount' => $stats['total_base_amount'],
                'posted_voucher_count' => $stats['posted_voucher_count'],
            ];
        });

        return $this->success([
            'project' => [
                'id' => $project->id,
                'project_code' => $project->project_code,
                'project_name' => $project->project_name,
            ],
            'fiscal_year' => [
                'id' => $fiscal_year->id,
                'name' => $fiscal_year->name,
                'start_date' => $fiscal_year->start_date,
                'end_date' => $fiscal_year->end_date,
            ],
            /** Currency in which period totals are expressed (journal book currency when set, else org base). */
            'base_currency' => $displayCurrency,
            'organization_base_currency' => $orgBaseCurrency,
            /** True when totals are sums of base_currency_amount (org reporting); false when journal book uses another currency and totals are in that currency. */
            'totals_in_organization_base' => $totalsUseOrganizationBase,
            'periods' => $data,
        ]);
    }

    public function closeProjectPeriod(Request $request, Project $project, FiscalPeriod $fiscal_period)
    {
        return $this->mutate($request, $project, $fiscal_period, 'close');
    }

    public function reopenProjectPeriod(Request $request, Project $project, FiscalPeriod $fiscal_period)
    {
        return $this->mutate($request, $project, $fiscal_period, 'reopen');
    }

    public function lockProjectPeriod(Request $request, Project $project, FiscalPeriod $fiscal_period)
    {
        return $this->mutate($request, $project, $fiscal_period, 'lock');
    }

    /**
     * Remove permanent lock so project posting can resume (Super Admin only).
     */
    public function unlockPermanentProjectPosting(Request $request, Project $project, FiscalPeriod $fiscal_period)
    {
        $denied = $this->authorizeSuperAdminOnly($request);
        if ($denied !== null) {
            return $denied;
        }

        if ((int) $project->organization_id !== (int) $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $fiscal_period->load('fiscalYear');
        if ((int) $fiscal_period->fiscalYear->organization_id !== (int) $request->user()->organization_id) {
            return $this->error('Period not found', 404);
        }

        $existing = ProjectFiscalPeriodStatus::query()
            ->where('project_id', $project->id)
            ->where('fiscal_period_id', $fiscal_period->id)
            ->first();

        if (! $existing || $existing->status !== 'locked') {
            return $this->error(
                'Only a permanently locked project period can be unlocked. Use Reopen if the period is temporarily closed.',
                422
            );
        }

        $existing->delete();

        return $this->success(null, 'Permanent lock removed. Project posting is open for this period again.');
    }

    private function mutate(Request $request, Project $project, FiscalPeriod $fiscal_period, string $action)
    {
        $denied = $action === 'lock'
            ? $this->authorizeManagePermanent($request)
            : $this->authorizeManageTemporary($request);
        if ($denied !== null) {
            return $denied;
        }

        if ((int) $project->organization_id !== (int) $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $fiscal_period->load('fiscalYear');
        if ((int) $fiscal_period->fiscalYear->organization_id !== (int) $request->user()->organization_id) {
            return $this->error('Period not found', 404);
        }

        $existing = ProjectFiscalPeriodStatus::query()
            ->where('project_id', $project->id)
            ->where('fiscal_period_id', $fiscal_period->id)
            ->first();

        if ($action === 'close') {
            if ($fiscal_period->status !== 'open') {
                return $this->error(
                    'The organization fiscal period must be open before you can close posting for a project.',
                    422
                );
            }
            if ($existing) {
                if ($existing->status === 'locked') {
                    return $this->error('This project period is permanently locked.', 422);
                }

                return $this->error('This project period is already closed for posting.', 422);
            }
            $row = ProjectFiscalPeriodStatus::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'fiscal_period_id' => $fiscal_period->id,
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $request->user()->id,
            ]);

            return $this->success($row->fresh(['project', 'fiscalPeriod']), 'Project period closed for posting');
        }

        if ($action === 'reopen') {
            if (! $existing) {
                return $this->error('This project period is already open for posting.', 422);
            }
            if ($existing->status === 'locked') {
                return $this->error('Locked project periods cannot be reopened.', 422);
            }
            $existing->delete();

            return $this->success(null, 'Project period reopened for posting');
        }

        if ($action === 'lock') {
            if (! $existing || $existing->status !== 'closed') {
                return $this->error('Close the project period for posting first, then you can lock it permanently.', 422);
            }

            $existing->update([
                'status' => 'locked',
                'closed_at' => $existing->closed_at ?? now(),
                'closed_by' => $existing->closed_by ?? $request->user()->id,
            ]);

            return $this->success($existing->fresh(['project', 'fiscalPeriod']), 'Project period locked permanently');
        }

        return $this->error('Invalid action', 400);
    }

    /**
     * @return \Illuminate\Http\JsonResponse|null null when allowed
     */
    private function authorizeView(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('super-admin')) {
            return null;
        }
        if (
            $user->can('view-period-close')
            || $user->can('manage-period-close')
            || $user->can('permanently-lock-period-close')
        ) {
            return null;
        }

        return $this->error('You do not have permission to view period close.', 403);
    }

    /**
     * Temporary close / reopen for project posting (not permanent lock).
     *
     * @return \Illuminate\Http\JsonResponse|null null when allowed
     */
    private function authorizeManageTemporary(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('super-admin')) {
            return null;
        }
        if ($user->can('manage-period-close')) {
            return null;
        }

        return $this->error('You do not have permission to temporarily close or reopen project periods.', 403);
    }

    /**
     * Permanently lock project posting after it is temporarily closed.
     *
     * @return \Illuminate\Http\JsonResponse|null null when allowed
     */
    private function authorizeManagePermanent(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('super-admin')) {
            return null;
        }
        if ($user->can('permanently-lock-period-close')) {
            return null;
        }

        return $this->error('You do not have permission to permanently lock project periods.', 403);
    }

    /**
     * @return \Illuminate\Http\JsonResponse|null null when allowed
     */
    private function authorizeSuperAdminOnly(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->hasRole('super-admin')) {
            return null;
        }

        return $this->error('Only Super Admin can perform this action.', 403);
    }

    /**
     * Posted vouchers for the project in each period: header project_id or any line project_id.
     * When the project journal book currency matches organization base, amounts use base_currency_amount.
     * When the journal book is in another currency (e.g. AFN vs USD base), totals sum voucher total_amount in that currency.
     *
     * @param  \Illuminate\Support\Collection<int, FiscalPeriod>  $periods
     * @return array<int, array{voucher_number_from: ?string, voucher_number_to: ?string, total_base_amount: string, posted_voucher_count: int}>
     */
    private function voucherStatsForProjectPeriods(
        Project $project,
        $periods,
        string $orgBaseCurrency,
        string $displayCurrency,
        bool $totalsUseOrganizationBase
    ): array {
        $orgId = $project->organization_id;
        $projectId = $project->id;
        $out = [];

        foreach ($periods as $fp) {
            $start = Carbon::parse($fp->start_date)->startOfDay();
            $end = Carbon::parse($fp->end_date)->endOfDay();

            $q = Voucher::query()
                ->where('organization_id', $orgId)
                ->where('status', 'posted')
                ->whereDate('voucher_date', '>=', $start->toDateString())
                ->whereDate('voucher_date', '<=', $end->toDateString())
                ->where(function ($q) use ($projectId) {
                    $q->where('project_id', $projectId)
                        ->orWhereHas('lines', fn ($l) => $l->where('project_id', $projectId));
                });

            if (! $totalsUseOrganizationBase) {
                $q->where('currency', $displayCurrency);
            }

            if ($totalsUseOrganizationBase) {
                $row = $q->clone()
                    ->selectRaw(
                        'MIN(voucher_number) as voucher_number_from, MAX(voucher_number) as voucher_number_to, '.
                        'COALESCE(SUM(COALESCE(base_currency_amount, total_amount)), 0) as total_base_amount, '.
                        'COUNT(*) as posted_voucher_count'
                    )->first();
            } else {
                $row = $q->clone()
                    ->selectRaw(
                        'MIN(voucher_number) as voucher_number_from, MAX(voucher_number) as voucher_number_to, '.
                        'COALESCE(SUM(COALESCE(total_amount, 0)), 0) as total_base_amount, '.
                        'COUNT(*) as posted_voucher_count'
                    )->first();
            }

            $out[$fp->id] = [
                'voucher_number_from' => $row?->voucher_number_from,
                'voucher_number_to' => $row?->voucher_number_to,
                'total_base_amount' => (string) ($row?->total_base_amount ?? '0'),
                'posted_voucher_count' => (int) ($row?->posted_voucher_count ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Journal book currency for period-close display and totals.
     * Order: (1) active journal linked to the project with currency set, (2) any journal referenced by
     * vouchers for this project with currency set, (3) dominant posted voucher currency, (4) project.currency.
     */
    private function resolveJournalBookCurrency(Project $project): ?string
    {
        $orgId = $project->organization_id;
        $projectId = $project->id;

        $projectJournals = Journal::query()
            ->where('organization_id', $orgId)
            ->where('project_id', $projectId)
            ->whereNotNull('currency')
            ->where('currency', '!=', '')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get(['id', 'currency']);

        if ($projectJournals->isNotEmpty()) {
            if ($projectJournals->count() === 1) {
                $only = $projectJournals->first()->currency;

                return strtoupper(trim((string) $only));
            }

            $journalIdsForCounts = $projectJournals->pluck('id')->all();
            $countsByJournalId = [];
            if ($journalIdsForCounts !== []) {
                $countsByJournalId = Voucher::query()
                    ->where('organization_id', $orgId)
                    ->where('status', 'posted')
                    ->whereIn('journal_id', $journalIdsForCounts)
                    ->where(function ($q) use ($projectId) {
                        $q->where('project_id', $projectId)
                            ->orWhereHas('lines', fn ($l) => $l->where('project_id', $projectId));
                    })
                    ->selectRaw('journal_id, COUNT(*) as c')
                    ->groupBy('journal_id')
                    ->pluck('c', 'journal_id');
            }

            $countsByCode = [];
            foreach ($projectJournals as $pj) {
                $code = strtoupper(trim((string) $pj->currency));
                if (! $this->nonEmptyCurrencyCode($code)) {
                    continue;
                }
                $n = (int) ($countsByJournalId[$pj->id] ?? 0);
                $countsByCode[$code] = ($countsByCode[$code] ?? 0) + $n;
            }

            if (! empty($countsByCode)) {
                arsort($countsByCode);
                $topCode = array_key_first($countsByCode);
                if ($topCode !== null && ($countsByCode[$topCode] ?? 0) > 0) {
                    return strtoupper(trim((string) $topCode));
                }
            }

            $first = $projectJournals->first();
            if ($first && $this->nonEmptyCurrencyCode($first->currency)) {
                return strtoupper(trim((string) $first->currency));
            }
        }

        $journalIds = Voucher::query()
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($projectId) {
                $q->where('project_id', $projectId)
                    ->orWhereHas('lines', fn ($l) => $l->where('project_id', $projectId));
            })
            ->whereNotNull('journal_id')
            ->distinct()
            ->pluck('journal_id');

        $journalIdList = $journalIds->filter()->unique()->values()->all();
        if ($journalIdList !== []) {
            $journalsById = Journal::query()
                ->where('organization_id', $orgId)
                ->whereIn('id', $journalIdList)
                ->get(['id', 'currency'])
                ->keyBy('id');
            foreach ($journalIdList as $jid) {
                $j = $journalsById->get((int) $jid);
                if ($j !== null && $this->nonEmptyCurrencyCode($j->currency)) {
                    return strtoupper(trim((string) $j->currency));
                }
            }
        }

        $dominant = Voucher::query()
            ->where('organization_id', $orgId)
            ->where('status', 'posted')
            ->where(function ($q) use ($projectId) {
                $q->where('project_id', $projectId)
                    ->orWhereHas('lines', fn ($l) => $l->where('project_id', $projectId));
            })
            ->whereNotNull('currency')
            ->where('currency', '!=', '')
            ->selectRaw('currency, COUNT(*) as c')
            ->groupBy('currency')
            ->orderByDesc('c')
            ->first();

        if ($dominant !== null && $this->nonEmptyCurrencyCode($dominant->currency)) {
            return strtoupper(trim((string) $dominant->currency));
        }

        $pc = $project->currency ?? null;
        if ($this->nonEmptyCurrencyCode($pc)) {
            return strtoupper(trim((string) $pc));
        }

        return null;
    }

    private function nonEmptyCurrencyCode(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
