<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\EvaluationLabel;
use App\Models\IssueReport;
use App\Services\Triage\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Admin Evaluation page: label real reports blind, see accuracy, export the set.
class EvaluationController extends ApiController
{
    public function __construct(private readonly EvaluationService $evaluation) {}

    public function next(Request $request): JsonResponse
    {
        $skip = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('skip', '')))));

        return response()->json(['success' => true, ...$this->evaluation->next(array_slice($skip, 0, 200))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'report_id' => ['required', 'integer', Rule::exists('ISSUE_REPORT', 'report_id')],
            'category_id' => ['required', 'integer', Rule::exists('CATEGORY', 'category_id')],
        ]);
        if ($validator->fails()) {
            return $this->error('Choose a valid report and category.', 422);
        }

        $data = $validator->validated();
        $actor = $this->currentUser();
        $label = EvaluationLabel::query()->find($data['report_id']);
        $changed = $label !== null && (int) $label->category_id !== (int) $data['category_id'];
        EvaluationLabel::query()->updateOrCreate(
            ['report_id' => $data['report_id']],
            [
                'category_id' => $data['category_id'],
                'labelled_by' => $actor->user_id,
                'labelled_at' => now(),
                // A changed label makes any earlier AI result for it stale.
                ...($changed ? ['ai_category_id' => null, 'ai_evaluated_at' => null] : []),
            ],
        );
        AdminAuditLog::create([
            'actor_user_id' => $actor->user_id,
            'action' => 'label_evaluation_report',
            'details' => ['report_id' => (int) $data['report_id'], 'category_id' => (int) $data['category_id']],
        ]);

        return response()->json(['success' => true]);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['success' => true, ...$this->evaluation->summary()]);
    }

    // CSV for the paper and for `php artisan triage:evaluate --file=...`. No names; contact
    // details in descriptions are masked.
    public function export(): StreamedResponse
    {
        $rows = $this->evaluation->rows();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['description', 'expected_category', 'resident_category', 'rules_prediction', 'ai_prediction'], escape: '\\');
            foreach ($rows as $row) {
                fputcsv($out, [$row['description'], $row['label'], $row['resident'], $row['rules'], $row['ai']], escape: '\\');
            }
            fclose($out);
        }, 'evaluation-set-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (! IssueReport::query()->whereKey($id)->exists() || ! EvaluationLabel::query()->whereKey($id)->delete()) {
            return $this->error('Label not found.', 404);
        }

        return response()->json(['success' => true]);
    }
}
