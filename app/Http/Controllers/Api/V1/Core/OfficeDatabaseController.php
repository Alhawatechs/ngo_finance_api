<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Services\OfficeContext;
use App\Services\OfficeProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Office database monitoring and backup/restore metadata.
 */
class OfficeDatabaseController extends Controller
{
    public function index(Request $request)
    {
        $offices = Office::where('organization_id', $request->user()->organization_id)
            ->orderBy('is_head_office', 'desc')
            ->orderBy('code')
            ->get(['id', 'name', 'code', 'is_head_office', 'database_name', 'database_connection', 'is_active']);

        $provisioning = app(OfficeProvisioningService::class);
        $items = $offices->map(function (Office $office) use ($provisioning) {
            $provisioned = $provisioning->isProvisioned($office);
            $status = 'central';
            $reachable = null;
            if ($provisioned) {
                $status = 'provisioned';
                $reachable = $this->checkReachable($office);
            }
            return [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
                'is_head_office' => $office->is_head_office,
                'database_name' => $office->database_name,
                'database_connection' => $office->database_connection,
                'status' => $status,
                'reachable' => $reachable,
            ];
        });

        return $this->success($items);
    }

    /**
     * Check if office database is reachable.
     */
    protected function checkReachable(Office $office): bool
    {
        if (!$office->database_connection || !$office->database_name) {
            return false;
        }
        try {
            OfficeContext::registerOfficeConnection($office);
            DB::connection($office->database_connection)->getPdo();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return backup/restore guidance (no actual backup - that is server-level).
     */
    public function backupInfo(Request $request)
    {
        $offices = Office::where('organization_id', $request->user()->organization_id)
            ->whereNotNull('database_name')
            ->get(['id', 'name', 'code', 'database_name']);

        $info = [
            'message' => 'Backup and restore are performed at the database server level (e.g. mysqldump, your cloud provider backups).',
            'office_databases' => $offices->map(fn (Office $o) => [
                'office_id' => $o->id,
                'name' => $o->name,
                'code' => $o->code,
                'database_name' => $o->database_name,
            ]),
        ];

        return $this->success($info);
    }
}
