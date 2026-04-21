<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Log an audit event. Call from observers or controllers.
     * Avoid passing sensitive fields (e.g. password) in old_values/new_values.
     */
    public static function log(
        string $action,
        Model $model,
        string $description,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $user = auth()->user();
        $request = request();

        AuditLog::create([
            'organization_id' => $model->organization_id ?? $user?->organization_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'url' => $request?->fullUrl(),
            'method' => $request?->method(),
        ]);
    }
}
