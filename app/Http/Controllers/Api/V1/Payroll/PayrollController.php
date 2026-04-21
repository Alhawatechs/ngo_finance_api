<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    /**
     * Display a listing of payroll runs.
     */
    public function index(Request $request)
    {
        $query = DB::table('payroll_runs')
            ->where('organization_id', $request->user()->organization_id)
            ->leftJoin('offices', 'payroll_runs.office_id', '=', 'offices.id')
            ->select('payroll_runs.*', 'offices.name as office_name');

        if ($request->has('status')) {
            $query->where('payroll_runs.status', $request->status);
        }

        if ($request->has('office_id')) {
            $query->where('payroll_runs.office_id', $request->office_id);
        }

        $payrollRuns = $query->orderBy('payroll_runs.period_end', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($payrollRuns);
    }

    /**
     * Store a newly created payroll run.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'office_id' => 'required|exists:offices,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'pay_date' => 'required|date|after_or_equal:period_end',
            'description' => 'nullable|string|max:255',
        ]);

        $runNumber = $this->generateRunNumber($request->user()->organization_id);

        $payrollRun = DB::table('payroll_runs')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'office_id' => $validated['office_id'],
            'run_number' => $runNumber,
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'pay_date' => $validated['pay_date'],
            'description' => $validated['description'] ?? null,
            'status' => 'draft',
            'total_gross' => 0,
            'total_deductions' => 0,
            'total_net' => 0,
            'employee_count' => 0,
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success(
            DB::table('payroll_runs')->find($payrollRun),
            'Payroll run created successfully',
            201
        );
    }

    /**
     * Display the specified payroll run.
     */
    public function show(Request $request, int $id)
    {
        $payrollRun = DB::table('payroll_runs')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$payrollRun) {
            return $this->error('Payroll run not found', 404);
        }

        // Get payroll items
        $items = DB::table('payroll_items')
            ->where('payroll_run_id', $id)
            ->get();

        return $this->success([
            'payroll_run' => $payrollRun,
            'items' => $items,
        ]);
    }

    /**
     * Add employee to payroll run.
     */
    public function addEmployee(Request $request, int $id)
    {
        $payrollRun = DB::table('payroll_runs')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$payrollRun) {
            return $this->error('Payroll run not found', 404);
        }

        if ($payrollRun->status !== 'draft') {
            return $this->error('Can only add employees to draft payroll runs', 400);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:users,id',
            'basic_salary' => 'required|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'overtime' => 'nullable|numeric|min:0',
            'bonuses' => 'nullable|numeric|min:0',
            'tax_deduction' => 'nullable|numeric|min:0',
            'social_security' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'project_id' => 'nullable|exists:projects,id',
            'cost_center' => 'nullable|string|max:255',
        ]);

        $gross = $validated['basic_salary'] + 
                 ($validated['allowances'] ?? 0) + 
                 ($validated['overtime'] ?? 0) + 
                 ($validated['bonuses'] ?? 0);

        $deductions = ($validated['tax_deduction'] ?? 0) + 
                      ($validated['social_security'] ?? 0) + 
                      ($validated['other_deductions'] ?? 0);

        $net = $gross - $deductions;

        DB::table('payroll_items')->insert([
            'payroll_run_id' => $id,
            'employee_id' => $validated['employee_id'],
            'basic_salary' => $validated['basic_salary'],
            'allowances' => $validated['allowances'] ?? 0,
            'overtime' => $validated['overtime'] ?? 0,
            'bonuses' => $validated['bonuses'] ?? 0,
            'gross_salary' => $gross,
            'tax_deduction' => $validated['tax_deduction'] ?? 0,
            'social_security' => $validated['social_security'] ?? 0,
            'other_deductions' => $validated['other_deductions'] ?? 0,
            'total_deductions' => $deductions,
            'net_salary' => $net,
            'project_id' => $validated['project_id'] ?? null,
            'cost_center' => $validated['cost_center'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update totals
        $this->updatePayrollTotals($id);

        return $this->success(null, 'Employee added to payroll');
    }

    /**
     * Process payroll run (create vouchers/journal entries).
     */
    public function process(Request $request, int $id)
    {
        $payrollRun = DB::table('payroll_runs')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$payrollRun) {
            return $this->error('Payroll run not found', 404);
        }

        if ($payrollRun->status !== 'draft') {
            return $this->error('Only draft payroll runs can be processed', 400);
        }

        $items = DB::table('payroll_items')
            ->where('payroll_run_id', $id)
            ->get();

        if ($items->isEmpty()) {
            return $this->error('Payroll run has no employees', 400);
        }

        // Update status to processed
        DB::table('payroll_runs')
            ->where('id', $id)
            ->update([
                'status' => 'processed',
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
                'updated_at' => now(),
            ]);

        return $this->success(null, 'Payroll processed successfully');
    }

    /**
     * Approve payroll run.
     */
    public function approve(Request $request, int $id)
    {
        $payrollRun = DB::table('payroll_runs')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$payrollRun) {
            return $this->error('Payroll run not found', 404);
        }

        if ($payrollRun->status !== 'processed') {
            return $this->error('Only processed payroll runs can be approved', 400);
        }

        DB::table('payroll_runs')
            ->where('id', $id)
            ->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'updated_at' => now(),
            ]);

        return $this->success(null, 'Payroll approved successfully');
    }

    /**
     * Get payroll summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $runs = DB::table('payroll_runs')
            ->where('organization_id', $orgId)
            ->get();

        $totalPaid = $runs->where('status', 'paid')->sum('total_net');
        $pendingApproval = $runs->where('status', 'processed')->count();

        $byStatus = $runs->groupBy('status')->map->count();

        // Current month payroll
        $currentMonthTotal = $runs
            ->where('status', 'approved')
            ->where('period_start', '>=', now()->startOfMonth())
            ->sum('total_net');

        return $this->success([
            'total_runs' => $runs->count(),
            'total_paid' => $totalPaid,
            'pending_approval' => $pendingApproval,
            'current_month_total' => $currentMonthTotal,
            'by_status' => $byStatus,
        ]);
    }

    /**
     * Update payroll totals.
     */
    private function updatePayrollTotals(int $payrollRunId): void
    {
        $totals = DB::table('payroll_items')
            ->where('payroll_run_id', $payrollRunId)
            ->selectRaw('COUNT(*) as employee_count, SUM(gross_salary) as total_gross, SUM(total_deductions) as total_deductions, SUM(net_salary) as total_net')
            ->first();

        DB::table('payroll_runs')
            ->where('id', $payrollRunId)
            ->update([
                'employee_count' => $totals->employee_count,
                'total_gross' => $totals->total_gross,
                'total_deductions' => $totals->total_deductions,
                'total_net' => $totals->total_net,
                'updated_at' => now(),
            ]);
    }

    /**
     * Generate payroll run number.
     */
    private function generateRunNumber(int $organizationId): string
    {
        $lastRun = DB::table('payroll_runs')
            ->where('organization_id', $organizationId)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastRun && preg_match('/PAY-(\d{4})-(\d+)/', $lastRun->run_number, $matches)) {
            $year = date('Y');
            $sequence = $matches[1] === $year ? (int)$matches[2] + 1 : 1;
        } else {
            $sequence = 1;
        }

        return sprintf('PAY-%s-%05d', date('Y'), $sequence);
    }
}
