<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AccountNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResidentController extends ApiController
{
    public function __construct(private readonly AccountNotificationService $accountNotifications) {}

    // List residents for administrator accounts.
    public function index(): JsonResponse
    {
        $residents = User::query()
            ->where('role', 'resident')
            ->withCount('reports')
            ->orderByRaw("CASE account_status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 WHEN 'suspended' THEN 2 ELSE 3 END")
            ->orderBy('full_name')
            ->orderBy('user_id')
            ->get()
            ->map(fn (User $resident): array => [
                'id' => (string) $resident->user_id,
                'username' => $resident->username,
                'fullName' => $resident->full_name,
                'email' => $resident->email,
                'accountStatus' => $resident->account_status,
                'registered' => Carbon::parse($resident->created_at, config('app.timezone'))->toIso8601String(),
                'reportCount' => $resident->reports_count,
            ]);

        return response()->json(['success' => true, 'residents' => $residents]);
    }

    // Approve, reject, or suspend a resident account.
    //   pending   -> approved | rejected
    //   rejected  -> approved
    //   approved  -> suspended (signs the resident out on their next request)
    //   suspended -> approved
    public function updateApproval(Request $request, int $id): JsonResponse
    {
        $actor = $this->currentUser();
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(['approved', 'rejected', 'suspended'])],
            'reason' => ['required_if:status,rejected,suspended', 'nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $status = $validator->validated()['status'];
        $reason = trim((string) ($validator->validated()['reason'] ?? ''));
        if ($status !== 'approved' && $reason === '') {
            return $this->error('Enter a reason for this account decision.', 422);
        }

        $allowedFrom = [
            'approved' => ['pending', 'rejected', 'suspended'],
            'rejected' => ['pending'],
            'suspended' => ['approved'],
        ];

        $result = DB::transaction(function () use ($id, $status, $reason, $actor, $allowedFrom): User|JsonResponse {
            // Lock the row so two administrators cannot apply the same decision twice.
            $resident = User::query()
                ->where('role', 'resident')
                ->where('user_id', $id)
                ->lockForUpdate()
                ->first();
            if (! $resident) {
                return $this->error('Resident account not found.', 404);
            }
            if (! in_array($resident->account_status, $allowedFrom[$status], true)) {
                return $this->error(match ($status) {
                    'approved' => 'This resident account is already approved.',
                    'rejected' => 'Only pending resident accounts can be rejected.',
                    'suspended' => 'Only approved resident accounts can be suspended.',
                }, 409);
            }

            $previousStatus = $resident->account_status;
            $resident->forceFill(['account_status' => $status])->save();
            if ($status !== 'approved') {
                // Rejected or suspended: any outstanding password reset link stops working.
                DB::table('PASSWORD_RESET_TOKENS')->where('email', $resident->email)->delete();
            }
            $details = ['resident_user_id' => $resident->user_id, 'previous_status' => $previousStatus];
            if ($reason !== '') {
                $details['reason'] = $reason;
            }
            AdminAuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => match ($status) {
                    'approved' => $previousStatus === 'suspended' ? 'reactivate_resident' : 'approve_resident',
                    'rejected' => 'reject_resident',
                    'suspended' => 'suspend_resident',
                },
                'details' => $details,
            ]);

            return $resident;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$subject, $message] = match ($status) {
            'approved' => [
                'Your account has been approved',
                'Your Barangay Reporting System account has been approved. You can now sign in.',
            ],
            'rejected' => [
                'Your account was not approved',
                "Your Barangay Reporting System account was not approved. Reason: {$reason}",
            ],
            'suspended' => [
                'Your account has been suspended',
                "Your Barangay Reporting System account has been suspended. Reason: {$reason}",
            ],
        };
        $emailStatus = $this->accountNotifications->send($result->email, $subject, $message);

        return response()->json(['success' => true, 'accountStatus' => $status, 'emailStatus' => $emailStatus]);
    }

    // Permanently remove a rejected resident account (for example, a non-resident or a
    // duplicate sign-up). Only rejected accounts qualify, so approved residents and their
    // report history can never be deleted from here.
    public function destroy(int $id): JsonResponse
    {
        $actor = $this->currentUser();

        return DB::transaction(function () use ($id, $actor): JsonResponse {
            $resident = User::query()
                ->where('role', 'resident')
                ->where('user_id', $id)
                ->lockForUpdate()
                ->first();
            if (! $resident) {
                return $this->error('Resident account not found.', 404);
            }
            if ($resident->account_status !== 'rejected') {
                return $this->error('Only rejected resident accounts can be deleted.', 409);
            }
            if ($resident->reports()->exists()) {
                return $this->error('This account has submitted reports and cannot be deleted.', 409);
            }

            // Notifications are removed by the database (ON DELETE CASCADE).
            DB::table('PASSWORD_RESET_TOKENS')->where('email', $resident->email)->delete();
            AdminAuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => 'delete_rejected_resident',
                'details' => ['resident_user_id' => $resident->user_id, 'username' => $resident->username],
            ]);
            $resident->delete();

            return response()->json(['success' => true]);
        });
    }
}
