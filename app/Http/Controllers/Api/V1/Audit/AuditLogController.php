<?php

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * List audit logs with filters (paginated).
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = AuditLog::query()
            ->byOrg($orgId)
            ->with('user:id,name')
            ->orderByDesc('created_at');

        if ($request->filled('user_id')) {
            $query->byUser((int) $request->user_id);
        }
        if ($request->filled('model_type')) {
            $query->byModel($request->model_type);
        }
        if ($request->filled('action')) {
            $query->byAction($request->action);
        }
        if ($request->filled('from') || $request->filled('to')) {
            $query->byDateRange($request->from, $request->to);
        }

        $paginator = $query->paginate($perPage);

        return $this->paginated($paginator, 'Audit logs retrieved.');
    }

    /**
     * Show a single audit log entry.
     */
    public function show(Request $request, int $id)
    {
        $orgId = $request->user()->organization_id;

        $log = AuditLog::where('organization_id', $orgId)->findOrFail($id);
        $log->load('user:id,name,email');

        return $this->success($log, 'Audit log retrieved.');
    }
}
