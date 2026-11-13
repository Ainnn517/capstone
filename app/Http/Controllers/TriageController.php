<?php

namespace App\Http\Controllers;

use App\Models\IssueReport;
use App\Services\Triage\TriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// AI-assisted triage endpoints. Responses are suggestions only; they never change data.
class TriageController extends ApiController
{
    public function __construct(private readonly TriageService $triage) {}

    // Resident: suggest a category for the description being written.
    public function suggestCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'description' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        if ($validator->fails()) {
            return $this->error('Write at least 10 characters to get a suggestion.', 422);
        }

        return response()->json([
            'success' => true,
            'suggestion' => $this->triage->suggestCategory(trim($validator->validated()['description'])),
        ]);
    }

    // Administrator: category check and possible duplicates for one active report.
    public function review(int $id): JsonResponse
    {
        $report = IssueReport::query()->whereNull('archived_at')->find($id);
        if (! $report) {
            return $this->error('Active report not found.', 404);
        }

        return response()->json(['success' => true, ...$this->triage->reviewReport($report)]);
    }
}
