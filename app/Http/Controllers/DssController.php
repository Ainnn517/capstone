<?php

namespace App\Http\Controllers;

use App\Models\DssAnalyticsRecord;
use App\Services\DssRecommendationService;
use Illuminate\Http\JsonResponse;

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

    /**
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        return DssAnalyticsRecord::query()
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
            'recommendations' => $record->recommendations,
        ];
    }
}
