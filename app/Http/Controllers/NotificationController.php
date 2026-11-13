<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        // Same groups as My Reports: "current" = updates about reports still in progress,
        // "past" = updates about reports that were resolved, rejected, or archived.
        $view = $request->query('view', 'all');
        if (! in_array($view, ['current', 'past', 'all'], true)) {
            return $this->error('Select a valid notification view.', 422);
        }
        $closed = ['Resolved', 'Rejected'];

        $notifications = Notification::query()
            ->with(['report:report_id,tracking_code'])
            ->where('user_id', $user->user_id)
            ->when($view === 'current', fn ($query) => $query->whereHas('report', fn ($reports) => $reports
                ->whereNotIn('status', $closed)
                ->whereNull('archived_at')))
            ->when($view === 'past', fn ($query) => $query->whereHas('report', fn ($reports) => $reports
                ->where(fn ($closedReports) => $closedReports->whereIn('status', $closed)->orWhereNotNull('archived_at'))))
            ->latest('timestamp')
            ->paginate((int) config('security.notification_page_size'), ['notif_id', 'report_id', 'message', 'type', 'timestamp', 'is_read'])
            ->withQueryString();

        return response()->json([
            'success' => true,
            'unreadCount' => Notification::where('user_id', $user->user_id)->where('is_read', false)->count(),
            'notifications' => $notifications->getCollection()
                ->map(fn (Notification $notification): array => [
                    'id' => (string) $notification->notif_id,
                    'tracking' => $notification->report?->tracking_code ?? 'Report',
                    'message' => $notification->message,
                    'type' => $notification->type,
                    'timestamp' => $notification->timestamp,
                    'isRead' => (bool) $notification->is_read,
                ])->values(),
            'pagination' => [
                'currentPage' => $notifications->currentPage(),
                'lastPage' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function markAsRead(int $id): JsonResponse
    {
        $updated = Notification::query()
            ->where('notif_id', $id)
            ->where('user_id', $this->currentUser()->user_id)
            ->update(['is_read' => true]);

        if ($updated === 0 && ! Notification::query()
            ->where('notif_id', $id)
            ->where('user_id', $this->currentUser()->user_id)
            ->where('is_read', true)
            ->exists()) {
            return $this->error('Notification not found.', 404);
        }

        return response()->json(['success' => true]);
    }
}
