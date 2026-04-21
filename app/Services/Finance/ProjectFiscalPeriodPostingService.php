<?php

namespace App\Services\Finance;

use App\Models\FiscalPeriod;
use App\Models\ProjectFiscalPeriodStatus;

class ProjectFiscalPeriodPostingService
{
    /**
     * Ensures no project-level close/lock blocks posting for the given fiscal period.
     * Call only when organization fiscal period is already open for posting.
     *
     * @param  array<int|null>  $projectIds
     *
     * @throws \InvalidArgumentException
     */
    public function assertProjectsOpenForPosting(FiscalPeriod $fiscalPeriod, array $projectIds): void
    {
        if (! in_array($fiscalPeriod->status, ['open', 'draft'], true)) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
        if ($ids === []) {
            return;
        }

        $blocked = ProjectFiscalPeriodStatus::query()
            ->where('fiscal_period_id', $fiscalPeriod->id)
            ->whereIn('project_id', $ids)
            ->with('project:id,project_code,project_name')
            ->get();

        if ($blocked->isEmpty()) {
            return;
        }

        $labels = $blocked->map(function ($row) {
            $p = $row->project;

            return $p ? "{$p->project_code} — {$p->project_name}" : (string) $row->project_id;
        })->implode('; ');

        throw new \InvalidArgumentException(
            "Project period close is in effect for: {$labels}. Reopen the period for each project under General Ledger → Period close, or adjust lines."
        );
    }
}
