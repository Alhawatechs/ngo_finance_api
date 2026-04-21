<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;

class NotificationService
{
    /**
     * Create a single in-app notification for a user.
     */
    public function notifyUser(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?array $data = null
    ): UserNotification {
        return UserNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'data' => $data,
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    /**
     * Notify multiple users with the same payload (e.g. all approvers).
     *
     * @param  array<int>  $userIds
     */
    public function notifyUsers(
        array $userIds,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?array $data = null
    ): void {
        $userIds = array_values(array_unique(array_filter($userIds)));
        foreach ($userIds as $uid) {
            $this->notifyUser((int) $uid, $type, $title, $message, $actionUrl, $data);
        }
    }

    /**
     * Users in the same organization who can approve at the given ladder level (1–4).
     *
     * @return array<int>
     */
    public function approverUserIdsForLevel(int $organizationId, int $minimumApprovalLevel): array
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull('approval_level')
            ->where('approval_level', '>=', $minimumApprovalLevel)
            ->pluck('id')
            ->all();
    }
}
