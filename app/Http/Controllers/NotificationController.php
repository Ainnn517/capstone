<?php

namespace App\Http\Controllers;

use App\Models\IssueReport;
use App\Models\Location;
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
        // Updates page tabs: "mine" = My Reports, "street" = My Street.
        $tab = $request->query('tab', 'mine');
        if (! in_array($tab, ['mine', 'street'], true)) {
            return $this->error('Select a valid updates tab.', 422);
        }
        if ($tab === 'street') {
            $view = 'all'; // the current/past filter is only for the resident's own reports
        }
        $closed = IssueReport::CLOSED_STATUSES;

        $notifications = Notification::query()
            ->with(['report:report_id,tracking_code,location_id', 'report.location:location_id,name,area_name'])
            ->where('user_id', $user->user_id)
            ->where('kind', $tab === 'street' ? 'street' : 'own')
            // A resolved report still waiting for the resident's answer counts as current.
            ->when($view === 'current', fn ($query) => $query->whereHas('report', fn ($reports) => $reports
                ->whereNull('archived_at')
                ->where(fn ($open) => $open->whereNotIn('status', $closed)->orWhere(fn ($awaiting) => $awaiting->awaitingConfirmation()))))
            ->when($view === 'past', fn ($query) => $query->whereHas('report', fn ($reports) => $reports
                ->where(fn ($closedReports) => $closedReports
                    ->where(fn ($done) => $done->whereIn('status', $closed)->whereNot(fn ($awaiting) => $awaiting->awaitingConfirmation()))
                    ->orWhereNotNull('archived_at'))))
            ->latest('timestamp')
            ->paginate((int) config('security.notification_page_size'), ['notif_id', 'report_id', 'message', 'type', 'kind', 'timestamp', 'is_read'])
            ->withQueryString();

        $unread = Notification::where('user_id', $user->user_id)->where('is_read', false)
            ->groupBy('kind')->selectRaw('kind, COUNT(*) as total')->pluck('total', 'kind');

        return response()->json([
            'success' => true,
            'unreadCount' => (int) $unread->sum(),
            'unreadByTab' => ['mine' => (int) ($unread['own'] ?? 0), 'street' => (int) ($unread['street'] ?? 0)],
            // For the My Street empty message; null when the resident has not saved a street.
            'street' => $this->streetName($user->address_location_id),
            'notifications' => $notifications->getCollection()
                ->map(fn (Notification $notification): array => [
                    'id' => (string) $notification->notif_id,
                    // Street updates never show another resident's tracking code, only the place.
                    'tracking' => $notification->kind === 'street'
                        ? ($this->placeName($notification->report?->location) ?? 'Your street')
                        : ($notification->report?->tracking_code ?? 'Report'),
                    'kind' => $notification->kind,
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

    private function streetName(?int $locationId): ?string
    {
        return $locationId === null ? null : $this->placeName(Location::query()->find($locationId, ['location_id', 'name', 'area_name']));
    }

    private function placeName(?Location $location): ?string
    {
        if ($location === null) {
            return null;
        }

        return $location->name === 'Area-wide' ? $location->area_name : "{$location->name}, {$location->area_name}";
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
