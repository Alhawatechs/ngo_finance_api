<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflowSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ApprovalWorkflowController extends Controller
{
    /**
     * Get approval workflow settings for the current user's organization.
     * Creates default settings if none exist.
     */
    public function show(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        if (!$organization) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        $settings = ApprovalWorkflowSetting::firstOrCreate(
            ['organization_id' => $organization->id],
            [
                'enable_approval_workflow' => true,
                'approval_levels' => 3,
                'approval_limit_level1' => 1000,
                'approval_limit_level2' => 10000,
                'approval_limit_level3' => 50000,
                'require_dual_signature' => true,
                'dual_signature_threshold' => 5000,
                'allow_self_approval' => false,
                'auto_approve_below' => 100,
                'require_supporting_documents' => true,
            ]
        );

        return response()->json([
            'data' => $this->formatSettings($settings),
            'base_currency' => $organization->default_currency ?? 'USD',
        ]);
    }

    /**
     * Update approval workflow settings.
     */
    public function update(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        if (!$organization) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        $settings = ApprovalWorkflowSetting::firstOrCreate(
            ['organization_id' => $organization->id],
            [
                'enable_approval_workflow' => true,
                'approval_levels' => 3,
                'approval_limit_level1' => 1000,
                'approval_limit_level2' => 10000,
                'approval_limit_level3' => 50000,
                'require_dual_signature' => true,
                'dual_signature_threshold' => 5000,
                'allow_self_approval' => false,
                'auto_approve_below' => 100,
                'require_supporting_documents' => true,
            ]
        );

        $validator = Validator::make($request->all(), [
            'enable_approval_workflow' => 'sometimes|boolean',
            'approval_levels' => 'sometimes|integer|min:1|max:3',
            'approval_limit_level1' => 'sometimes|numeric|min:0',
            'approval_limit_level2' => 'sometimes|numeric|min:0',
            'approval_limit_level3' => 'sometimes|numeric|min:0',
            'require_dual_signature' => 'sometimes|boolean',
            'dual_signature_threshold' => 'sometimes|numeric|min:0',
            'allow_self_approval' => 'sometimes|boolean',
            'auto_approve_below' => 'sometimes|numeric|min:0',
            'require_supporting_documents' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $settings->update($validator->validated());

        return response()->json([
            'message' => 'Approval workflow updated successfully',
            'data' => $this->formatSettings($settings),
            'base_currency' => $organization->default_currency ?? 'USD',
        ]);
    }

    private function formatSettings(ApprovalWorkflowSetting $settings): array
    {
        return [
            'id' => $settings->id,
            'organization_id' => $settings->organization_id,
            'enable_approval_workflow' => (bool) $settings->enable_approval_workflow,
            'approval_levels' => (int) $settings->approval_levels,
            'approval_limit_level1' => $settings->approval_limit_level1,
            'approval_limit_level2' => $settings->approval_limit_level2,
            'approval_limit_level3' => $settings->approval_limit_level3,
            'require_dual_signature' => (bool) $settings->require_dual_signature,
            'dual_signature_threshold' => $settings->dual_signature_threshold,
            'allow_self_approval' => (bool) $settings->allow_self_approval,
            'auto_approve_below' => $settings->auto_approve_below,
            'require_supporting_documents' => (bool) $settings->require_supporting_documents,
            'updated_at' => $settings->updated_at?->toIso8601String(),
        ];
    }
}
