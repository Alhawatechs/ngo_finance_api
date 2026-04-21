<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Paginated notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $unreadOnly = filter_var($request->input('unread_only'), FILTER_VALIDATE_BOOLEAN);
        $type = $request->input('type');

        $query = UserNotification::query()
            ->forUser($user->id)
            ->orderByDesc('created_at');

        if ($unreadOnly) {
            $query->unread();
        }
        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        $paginator = $query->paginate($perPage);

        return $this->paginated($paginator);
    }

    /**
     * Lightweight unread count for header badge.
     */
    public function unreadCount(Request $request)
    {
        $count = UserNotification::query()
            ->forUser($request->user()->id)
            ->unread()
            ->count();

        return $this->success(['unread_count' => $count]);
    }

    /**
     * Recent notifications for header dropdown (limited).
     */
    public function recent(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 10), 1), 30);

        $items = UserNotification::query()
            ->forUser($request->user()->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->success($items);
    }

    public function markRead(Request $request, int $id)
    {
        $notification = UserNotification::query()
            ->forUser($request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        $notification->markRead();

        return $this->success($notification->fresh(), 'Marked as read');
    }

    public function markAllRead(Request $request)
    {
        $userId = $request->user()->id;

        UserNotification::query()
            ->forUser($userId)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return $this->success(['ok' => true], 'All notifications marked as read');
    }

    public function destroy(Request $request, int $id)
    {
        $notification = UserNotification::query()
            ->forUser($request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        $notification->delete();

        return $this->success(null, 'Notification removed');
    }
}
