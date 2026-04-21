<?php

namespace App\Services;

use App\Models\Office;
use Illuminate\Support\Collection;

/**
 * Aggregates financial data across all office databases for consolidated reporting.
 */
class ConsolidatedReportService
{
    public function __construct(
        protected OfficeContext $officeContext
    ) {}

    /**
     * Run a callback for each office that has a provisioned DB and collect results.
     *
     * @param callable $callback Receives (Office $office) and returns array|Collection
     * @return Collection Collection of [office_id => result, ...]
     */
    public function aggregateByOffice(callable $callback): Collection
    {
        $user = request()->user();
        if (!$user) {
            return collect();
        }
        $offices = Office::where('organization_id', $user->organization_id)->get();
        $results = collect();
        foreach ($offices as $office) {
            $result = OfficeContext::runWithOffice($office, fn () => $callback($office));
            $results[$office->id] = $result;
        }
        return $results;
    }

    /**
     * Sum a numeric value across all office DBs (e.g. total balance, total count).
     *
     * @param string $modelClass Model class (e.g. Voucher::class) - must use UsesOfficeConnection
     * @param string $column Column to sum
     * @param array $filters Optional query constraints
     */
    public function sumAcrossOffices(string $modelClass, string $column, array $filters = []): float
    {
        $results = $this->aggregateByOffice(function (Office $office) use ($modelClass, $column, $filters) {
            $query = $modelClass::query();
            foreach ($filters as $key => $value) {
                $query->where($key, $value);
            }
            return (float) $query->sum($column);
        });
        return $results->sum();
    }

    /**
     * Get combined list from all offices (e.g. all vouchers with office_id/office_name attached).
     *
     * @param string $modelClass
     * @param array $queryConstraints
     * @param array $columns
     * @return Collection
     */
    public function listFromAllOffices(string $modelClass, array $queryConstraints = [], array $columns = ['*']): Collection
    {
        $results = $this->aggregateByOffice(function (Office $office) use ($modelClass, $queryConstraints, $columns) {
            $query = $modelClass::query()->select($columns);
            foreach ($queryConstraints as $key => $value) {
                $query->where($key, $value);
            }
            return $query->get()->map(function ($item) use ($office) {
                $item->office_id = $office->id;
                $item->office_name = $office->name;
                return $item;
            });
        });
        return $results->flatten(1);
    }
}
