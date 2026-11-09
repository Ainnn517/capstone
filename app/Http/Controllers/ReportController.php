<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Notification;
use App\Models\StatusLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportController extends ApiController
{
    private const STATUSES = ['Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected'];

    // List reports visible to the signed-in user.
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        $reportsQuery = IssueReport::with([
            'category',
            'location',
            'team',
            'latestStatusLog',
            'latestAttachment' => fn ($query) => $query->select(
                'ATTACHMENT.attachment_id',
                'ATTACHMENT.report_id',
                'ATTACHMENT.mime_type',
            ),
        ])->whereNull('archived_at')
            ->orderByDesc('date_submitted')
            ->orderByDesc('report_id');

        if ($user->role !== 'admin') {
            $reportsQuery->where('user_id', $user->user_id);
            $view = $request->query('view', 'all');
            if ($view === 'current') {
                $reportsQuery->whereNotIn('status', ['Resolved', 'Rejected']);
            } elseif ($view === 'past') {
                $reportsQuery->whereIn('status', ['Resolved', 'Rejected']);
            } elseif ($view !== 'all') {
                return $this->error('Select a valid report view.', 422);
            }
        } else {
            $view = $request->query('view', 'all');
            if ($view === 'active') {
                $reportsQuery->where('status', '<>', 'Resolved');
            } elseif ($view === 'resolved') {
                $reportsQuery->where('status', 'Resolved');
            } elseif ($view !== 'all') {
                return $this->error('Select a valid report view.', 422);
            }

            $status = $request->query('status');
            if ($status && $status !== 'All Status') {
                if (! in_array($status, self::STATUSES, true)) {
                    return $this->error('Select a valid report status.', 422);
                }
                $reportsQuery->where('status', $status);
            }

            $priority = $request->query('priority');
            if ($priority && $priority !== 'All Priority') {
                $range = match ($priority) {
                    'Normal' => [1, 2],
                    'High' => [3, 4],
                    'Critical' => [5, 255],
                    default => null,
                };
                if ($range === null) {
                    return $this->error('Select a valid report priority.', 422);
                }
                $reportsQuery->whereHas('category', fn ($query) => $query->whereBetween('severity_weight', $range));
            }
        }

        $reports = $reportsQuery->paginate(50)->withQueryString();

        return $this->reportsResponse($reports, $user);
    }

    // Let administrators review reports removed from active lists.
    public function archived(): JsonResponse
    {
        $user = $this->currentUser();
        $reports = IssueReport::with([
            'category',
            'location',
            'team',
            'latestStatusLog',
            'latestAttachment' => fn ($query) => $query->select(
                'ATTACHMENT.attachment_id',
                'ATTACHMENT.report_id',
                'ATTACHMENT.mime_type',
            ),
        ])->whereNotNull('archived_at')
            ->orderByDesc('archived_at')
            ->orderByDesc('report_id')
            ->paginate(50)
            ->withQueryString();

        return $this->reportsResponse($reports, $user);
    }

    // Return aggregate data without loading report attachments or every report row.
    public function summary(): JsonResponse
    {
        $reports = DB::table('ISSUE_REPORT')->whereNull('archived_at');
        $total = (clone $reports)->count();
        $resolved = (clone $reports)->where('status', 'Resolved')->count();
        $dispatches = (clone $reports)->where('status', 'Dispatched')->count();
        $categories = DB::table('ISSUE_REPORT as report')
            ->join('CATEGORY as category', 'category.category_id', '=', 'report.category_id')
            ->whereNull('report.archived_at')
            ->groupBy('category.name')
            ->selectRaw('category.name as category, COUNT(*) as report_count')
            ->get()
            ->keyBy('category')
            ->map(fn ($row): int => (int) $row->report_count);
        $monthly = array_fill(1, 12, 0);
        DB::table('ISSUE_REPORT')
            ->whereNull('archived_at')
            ->whereYear('date_submitted', now()->year)
            ->selectRaw('MONTH(date_submitted) as month, COUNT(*) as report_count')
            ->groupByRaw('MONTH(date_submitted)')
            ->get()
            ->each(function ($row) use (&$monthly): void {
                $monthly[(int) $row->month] = (int) $row->report_count;
            });
        $hotspots = DB::table('ISSUE_REPORT as report')
            ->join('CATEGORY as category', 'category.category_id', '=', 'report.category_id')
            ->join('LOCATION as location', 'location.location_id', '=', 'report.location_id')
            ->whereNull('report.archived_at')
            ->whereNotIn('report.status', ['Resolved', 'Rejected'])
            ->groupBy('location.name', 'location.area_name', 'category.name')
            ->selectRaw(
                "CONCAT(location.name, ', ', location.area_name) as location, category.name as category, COUNT(*) as report_count",
            )
            ->get()
            ->map(fn ($row): array => [
                'location' => $row->location,
                'category' => $row->category,
                'count' => (int) $row->report_count,
            ]);
        $hotspotCount = $hotspots->groupBy('location')
            ->map(fn ($groups): int => $groups->sum('count'))
            ->filter(fn (int $count): bool => $count >= 3)
            ->count();

        return response()->json([
            'success' => true,
            'summary' => [
                'total' => $total,
                'resolved' => $resolved,
                'dispatches' => $dispatches,
                'resolutionRate' => $total === 0 ? 0 : (int) round(($resolved / $total) * 100),
                'hotspotCount' => $hotspotCount,
                'categories' => $categories,
                'monthly' => array_values($monthly),
                'hotspots' => $hotspots,
            ],
        ]);
    }

    // Archive one unresolved report selected by an administrator.
    public function archive(int $id): JsonResponse
    {
        $user = $this->currentUser();
        $report = IssueReport::where('report_id', $id)
            ->whereNull('archived_at')
            ->first();

        if (! $report) {
            return $this->error('Active report not found.', 404);
        }
        if ($report->status === 'Resolved') {
            return $this->error('Resolved reports cannot be archived.', 409);
        }

        $report->update([
            'archived_at' => now(),
            'archived_by' => $user->user_id,
        ]);

        return response()->json(['success' => true]);
    }

    // Restore one archived report to the active report lists.
    public function restore(int $id): JsonResponse
    {
        $report = IssueReport::where('report_id', $id)
            ->whereNotNull('archived_at')
            ->first();

        if (! $report) {
            return $this->error('Archived report not found.', 404);
        }

        $report->update([
            'archived_at' => null,
            'archived_by' => null,
        ]);

        return response()->json(['success' => true]);
    }

    // Permanently delete a report only after it has been archived.
    public function destroyArchived(int $id): JsonResponse
    {
        $report = IssueReport::where('report_id', $id)
            ->whereNotNull('archived_at')
            ->first();

        if (! $report) {
            return $this->error('Archived report not found.', 404);
        }

        $report->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @param  LengthAwarePaginator<int, IssueReport>  $reports
     */
    private function reportsResponse(LengthAwarePaginator $reports, User $user): JsonResponse
    {
        $formattedReports = $reports->getCollection()->map(function (IssueReport $report) use ($user): array {
            $location = $report->location->name.', '.$report->location->area_name;
            $landmark = $report->landmark ?? '';
            if ($landmark === '' && $report->latitude !== null && $report->longitude !== null) {
                $landmark = 'GPS '.$report->latitude.', '.$report->longitude;
            }
            $remarks = $report->latestStatusLog?->remarks;

            return [
                'id' => (string) $report->report_id,
                'residentId' => $user->role === 'admin' ? (string) $report->user_id : null,
                'tracking' => $report->tracking_code,
                'category' => $report->category->name,
                'purok' => $location,
                'description' => $report->description,
                'location' => $landmark,
                'latitude' => $report->latitude,
                'longitude' => $report->longitude,
                'priority' => $this->priorityForWeight((int) $report->category->severity_weight),
                'stage' => $report->status,
                'created' => str_replace(' ', 'T', (string) $report->date_submitted),
                'resolved' => $report->date_resolved
                    ? str_replace(' ', 'T', (string) $report->date_resolved)
                    : null,
                'assignee' => $report->team?->name ?? 'Unassigned',
                'dispatchNote' => $remarks ?? '',
                'archivedAt' => $report->archived_at
                    ? str_replace(' ', 'T', (string) $report->archived_at)
                    : null,
                'attachmentId' => $report->latestAttachment
                    ? (string) $report->latestAttachment->attachment_id
                    : null,
                'updatedMessage' => $remarks ?: 'Report received and queued for administrative validation.',
            ];
        });

        return response()->json([
            'success' => true,
            'reports' => $formattedReports->values(),
            'pagination' => [
                'currentPage' => $reports->currentPage(),
                'lastPage' => $reports->lastPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    // Validate and save a resident's report and optional image.
    public function store(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        $validator = Validator::make($request->all(), [
            'category' => ['required', 'string', 'max:100'],
            'purok' => ['required', 'string', 'max:302'],
            'description' => ['required', 'string', 'min:1', 'max:500'],
            'location' => ['nullable', 'string', 'max:150'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $category = Category::where('name', trim($data['category']))->first();
        if (! $category) {
            return $this->error('Select a valid issue category.');
        }

        $locationParts = explode(', ', trim($data['purok']), 2);
        if (count($locationParts) === 1 && $locationParts[0] !== '') {
            [$street, $area] = ['Area-wide', $locationParts[0]];
        } elseif (count($locationParts) === 2 && $locationParts[0] !== '' && $locationParts[1] !== '') {
            [$street, $area] = $locationParts;
        } else {
            return $this->error('Choose a valid street and village or phase.');
        }

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        if (($latitude !== null && $latitude !== '' && (! is_numeric($latitude) || $latitude < -90 || $latitude > 90))
            || ($longitude !== null && $longitude !== '' && (! is_numeric($longitude) || $longitude < -180 || $longitude > 180))) {
            return $this->error('GPS coordinates are invalid.');
        }
        $latitude = $latitude === '' ? null : $latitude;
        $longitude = $longitude === '' ? null : $longitude;
        if (($latitude === null) !== ($longitude === null)) {
            return $this->error('Both GPS coordinates are required.');
        }

        $image = $request->file('image');
        if ($image) {
            $fileValidator = Validator::make(['image' => $image], [
                'image' => ['file', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
            ]);
            if ($fileValidator->fails()) {
                return $this->error('Image upload failed or exceeds the 5 MB limit.');
            }
            if (! in_array($image->getMimeType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                return $this->error('Upload a JPG, PNG, GIF, or WebP image.');
            }
        }

        $imagePath = $image?->getRealPath();
        if ($image && ! is_string($imagePath)) {
            return $this->error('Could not read the uploaded image.');
        }
        $imageData = $imagePath !== null ? file_get_contents($imagePath) : null;
        if ($image && $imageData === false) {
            return $this->error('Could not read the uploaded image.');
        }

        $trackingCode = 'BR-'.now('UTC')->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
        $report = DB::transaction(function () use ($user, $category, $street, $area, $data, $latitude, $longitude, $trackingCode, $image, $imageData): IssueReport {
            $location = Location::firstOrCreate(['name' => $street, 'area_name' => $area]);
            $report = IssueReport::create([
                'tracking_code' => $trackingCode,
                'user_id' => $user->user_id,
                'category_id' => $category->category_id,
                'description' => trim($data['description']),
                'location_id' => $location->location_id,
                'landmark' => trim((string) ($data['location'] ?? '')) ?: null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            StatusLog::create([
                'report_id' => $report->report_id,
                'changed_by' => $user->user_id,
                'new_status' => 'Submitted',
                'remarks' => 'Report submitted.',
            ]);
            Notification::create([
                'report_id' => $report->report_id,
                'user_id' => $user->user_id,
                'message' => 'Your report has been submitted.',
                'type' => 'submitted',
            ]);

            if ($image) {
                Attachment::create([
                    'report_id' => $report->report_id,
                    'mime_type' => $image->getMimeType(),
                    'file_data' => $imageData,
                ]);
            }

            return $report;
        });

        return response()->json([
            'success' => true,
            'id' => (string) $report->report_id,
            'tracking' => $trackingCode,
        ], 201);
    }

    // Update a report's status, assignment, history, and notification.
    public function update(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'assignee' => ['required', 'string', 'max:100'],
            'dispatchNote' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $dispatchNote = trim($data['dispatchNote']);
        if ($dispatchNote === '') {
            return $this->error('Enter a message for the resident before saving.', 422);
        }

        $teamName = $data['assignee'];
        $team = $teamName === 'Unassigned' ? null : Team::where('name', $teamName)->first();
        if ($teamName !== 'Unassigned' && ! $team) {
            return $this->error('The selected team does not exist.', 422);
        }

        $result = DB::transaction(function () use ($data, $user, $team, $teamName, $dispatchNote): ?JsonResponse {
            $report = IssueReport::where('report_id', $data['id'])
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();
            if (! $report) {
                return $this->error('Report not found.', 404);
            }
            if ($report->status === 'Resolved') {
                return $this->error('Resolved incidents are read-only and cannot be changed.', 409);
            }

            $oldStatus = $report->status;
            $report->status = $data['status'];
            $report->team_id = $team?->team_id;
            $report->date_resolved = $data['status'] === 'Resolved'
                ? DB::raw('GREATEST(CURRENT_TIMESTAMP, date_submitted)')
                : null;
            $report->save();

            StatusLog::create([
                'report_id' => $report->report_id,
                'changed_by' => $user->user_id,
                'old_status' => $oldStatus,
                'new_status' => $data['status'],
                'remarks' => $dispatchNote,
            ]);

            $type = $data['status'] === 'Resolved'
                ? 'resolved'
                : ($teamName !== 'Unassigned' ? 'assigned' : 'status_update');
            Notification::create([
                'report_id' => $report->report_id,
                'user_id' => $report->user_id,
                'message' => $dispatchNote,
                'type' => $type,
            ]);

            return null;
        });

        return $result ?? response()->json(['success' => true]);
    }

    // Archive reports that have not been resolved.
    public function clear(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'confirmed' => ['required', 'accepted'],
        ]);
        if ($validator->fails()) {
            return $this->error('Confirm the bulk archive action before continuing.', 422);
        }

        $user = $this->currentUser();
        $archived = DB::transaction(function () use ($user): int {
            $count = IssueReport::whereNull('archived_at')
                ->where('status', '<>', 'Resolved')
                ->update([
                    'archived_at' => now(),
                    'archived_by' => $user->user_id,
                ]);
            AdminAuditLog::create([
                'actor_user_id' => $user->user_id,
                'action' => 'bulk_archive_unresolved_reports',
                'details' => ['archived_count' => $count],
            ]);

            return $count;
        });

        return response()->json(['success' => true, 'archived' => $archived]);
    }
}
