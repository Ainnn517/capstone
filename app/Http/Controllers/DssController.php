<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\DssAnalyticsRecord;
use App\Services\DssRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DssController extends ApiController
{
    public function index(DssRecommendationService $recommendations): JsonResponse
    {
        $result = $recommendations->recommendations();

        return response()->json([
            'success' => true,
            ...$result,
            'history' => $this->history(),
        ]);
    }

    public function store(DssRecommendationService $recommendations): JsonResponse
    {
        $result = $recommendations->recommendations();
        $snapshot = DssAnalyticsRecord::create([
            'created_by' => $this->currentUser()->user_id,
            'source_report_count' => $result['sourceReportCount'],
            'recommendations' => $result['recommendations'],
        ])->refresh();

        return response()->json([
            'success' => true,
            'snapshot' => $this->formatSnapshot($snapshot),
        ], 201);
    }

    // Delete every saved snapshot. Live recommendations are calculated from current reports
    // and are not affected. The request must confirm explicitly, and the action is audited.
    public function destroyAll(Request $request): JsonResponse
    {
        if ($request->input('confirmed') !== true) {
            return $this->error('Confirm clearing the snapshot history before continuing.', 422);
        }

        $actor = $this->currentUser();
        $cleared = DB::transaction(function () use ($actor): int {
            $count = DssAnalyticsRecord::query()->count();
            DssAnalyticsRecord::query()->delete();
            AdminAuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => 'clear_dss_snapshots',
                'details' => ['cleared_count' => $count],
            ]);

            return $count;
        });

        return response()->json(['success' => true, 'cleared' => $cleared]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        return DssAnalyticsRecord::query()
            ->with('creator:user_id,full_name')
            ->latest('generated_at')
            ->limit(10)
            ->get()
            ->map(fn (DssAnalyticsRecord $record): array => $this->formatSnapshot($record))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSnapshot(DssAnalyticsRecord $record): array
    {
        return [
            'id' => (int) $record->snapshot_id,
            'generatedAt' => $record->generated_at->toAtomString(),
            'sourceReportCount' => (int) $record->source_report_count,
            'savedBy' => $record->creator?->full_name,
            'recommendations' => $record->recommendations,
        ];
    }
}
